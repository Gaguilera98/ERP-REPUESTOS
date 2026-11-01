<?php

use App\Caja\AbrirCajaSesion;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Models\Almacen;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::connection('pool_shared_1')->dropAllTables();

    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

/**
 * @return array{
 *     cajero: TenantUser,
 *     supervisor: TenantUser,
 *     sucursal: Sucursal,
 *     almacen: Almacen,
 *     variante: ProductoVariante,
 *     ocasional: Proveedor
 * }
 */
function negocioConMostrador(string $dominio = 'venta'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');

    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $almacen = Almacen::factory()->create(['nombre' => 'Estante']);
    SucursalAlmacen::query()->create([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'es_principal' => true,
    ]);

    $variante = ProductoVariante::factory()->create([
        'sku' => '6204-2RS',
        'precio_venta' => '100.00',
        'precio_mayor' => '80.00',
        'precio_costo' => '40.0000',
    ]);

    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('cajero', 'supervisor', 'sucursal', 'almacen', 'variante', 'ocasional');
}

function cargarStock(ProductoVariante $variante, Almacen $almacen, TenantUser $usuario, Proveedor $ocasional, string $cantidad = '5'): void
{
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'forma_impuesto' => 'importe',
        'impuestos' => 0,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => $cantidad,
            'costo_unitario' => '40',
        ]],
    ], $usuario);

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => $cantidad],
    ], $usuario, true);
}

it('completa una venta de contado con código VNT, kardex y pago aplicado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador();

    cargarStock($variante, $almacen, $cajero, $ocasional, '3');
    app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '50',
    ], $cajero);

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad' => '2',
        ]],
        'pagos' => [[
            'metodo_pago' => MetodoPago::Efectivo->value,
            'monto' => '200',
        ]],
    ], $cajero);

    $venta = $resultado['venta'];

    expect($venta->codigo)->toBe('VNT-000001')
        ->and($venta->estado)->toBe(EstadoVenta::Completada)
        ->and($venta->total)->toBe('200.00')
        ->and($venta->detalles)->toHaveCount(1)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('1.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::SalidaVenta)->count())->toBe(1)
        ->and(Pago::query()->count())->toBe(1)
        ->and(PagoAplicacion::query()->where('venta_id', $venta->id)->count())->toBe(1)
        ->and(NegocioConfiguracion::query()->value('siguiente_numero_venta'))->toBe(2);
});

it('no vende sin caja abierta', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('sincaja');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('no deja stock negativo ni vender más de lo disponible', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('sinstock');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '2']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '200']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('exige supervisor si el descuento pasa el tope', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('descuento');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad' => '1',
            'tipo_descuento' => TipoDescuento::Porcentaje->value,
            'valor_descuento' => '20',
        ]],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '80']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('permite cobro mixto y guarda path de comprobante', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('mixto');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [
            [
                'metodo_pago' => MetodoPago::Efectivo->value,
                'monto' => '40',
            ],
            [
                'metodo_pago' => MetodoPago::Transferencia->value,
                'monto' => '60',
                'numero_referencia_banco' => 'TRX-1',
                'comprobante_archivo_path' => 'pagos/trx-1.pdf',
            ],
        ],
    ], $cajero);

    $pagos = Pago::query()->orderBy('id')->get();

    expect($resultado['venta']->total)->toBe('100.00')
        ->and($pagos)->toHaveCount(2)
        ->and($pagos[0]->caja_sesion_id)->not->toBeNull()
        ->and($pagos[1]->caja_sesion_id)->toBeNull()
        ->and($pagos[1]->comprobante_archivo_path)->toBe('pagos/trx-1.pdf')
        ->and($pagos[1]->numero_referencia_banco)->toBe('TRX-1');
});

it('hace compra flash con stock en cero y luego vende', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante] = negocioConMostrador('flash');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('0.0000');

    $flash = app(CompraFlash::class)->ejecutar([
        'almacen_id' => $almacen->id,
        'producto_variante_id' => $variante->id,
        'cantidad' => '2',
        'costo_unitario' => '35',
        'confirmo_costos' => true,
    ], $cajero);

    expect($flash['orden']->es_compra_flash)->toBeTrue()
        ->and(MovimientoInventario::query()->where('tipo_movimiento', TipoMovimientoInventario::EntradaFlashEmergencia)->count())->toBe(1)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('2.0000');

    $resultado = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '100']],
    ], $cajero);

    expect($resultado['venta']->codigo)->toStartWith('VNT-')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->value('cantidad_actual'))->toBe('1.0000');
});

it('crea producto nuevo en compra flash', function () {
    ['cajero' => $cajero, 'almacen' => $almacen] = negocioConMostrador('flashnuevo');

    $flash = app(CompraFlash::class)->ejecutar([
        'almacen_id' => $almacen->id,
        'cantidad' => '1',
        'costo_unitario' => '12.5',
        'confirmo_costos' => true,
        'producto_nuevo' => [
            'nombre' => 'Junta flash',
            'sku' => 'JF-1',
            'precio_venta' => '25',
        ],
    ], $cajero);

    expect($flash['variante']->sku)->toBe('JF-1')
        ->and(InventarioStock::query()->where('producto_variante_id', $flash['variante']->id)->value('cantidad_actual'))->toBe('1.0000');
});

it('no permite actualizar una venta completada vía policy', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('noedit');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajero)['venta'];

    expect($cajero->can('update', $venta))->toBeFalse();
});
