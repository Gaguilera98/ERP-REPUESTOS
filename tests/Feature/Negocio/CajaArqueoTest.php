<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Models\Almacen;
use App\Models\CajaSesionMedio;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Ventas\CompletarVentaContado;
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
 *     sucursal: Sucursal,
 *     almacen: Almacen,
 *     variante: ProductoVariante,
 *     ocasional: Proveedor
 * }
 */
function negocioConArqueo(string $dominio = 'arqueo'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

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
        'sku' => 'ARQ-1',
        'precio_venta' => '100.00',
        'precio_costo' => '40.0000',
    ]);

    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('cajero', 'sucursal', 'almacen', 'variante', 'ocasional');
}

function stockParaArqueo(ProductoVariante $variante, Almacen $almacen, TenantUser $usuario, Proveedor $ocasional, string $cantidad = '5'): void
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

/**
 * @return array<string, string>
 */
function mediosSegunArqueo($sesion, array $cambios = []): array
{
    $medios = [];

    foreach (app(ArqueoCajaSesion::class)->de($sesion)['por_metodo'] as $fila) {
        $medios[$fila['metodo']] = $cambios[$fila['metodo']] ?? $fila['esperado'];
    }

    return $medios;
}

it('suma el efectivo cobrado al teórico del turno y desglosa los medios', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConArqueo();

    stockParaArqueo($variante, $almacen, $cajero, $ocasional, '2');
    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '50',
    ], $cajero);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [
            ['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '40'],
            [
                'metodo_pago' => MetodoPago::Transferencia->value,
                'monto' => '60',
                'numero_referencia_banco' => 'TRX-ARQ',
            ],
        ],
    ], $cajero);

    $arqueo = app(ArqueoCajaSesion::class)->de($sesion->fresh());

    expect($arqueo['apertura'])->toBe('50.00')
        ->and($arqueo['cobros_efectivo'])->toBe('40.00')
        ->and($arqueo['teorico_efectivo'])->toBe('90.00')
        ->and($arqueo['total_cobros'])->toBe('100.00')
        ->and(collect($arqueo['por_metodo'])->firstWhere('metodo', 'transferencia')['esperado'])->toBe('60.00')
        ->and(collect($arqueo['por_metodo'])->firstWhere('metodo', 'qr_externo')['esperado'])->toBe('0.00');
});

it('no deja cerrar con cero en qr si hubo ventas por qr sin justificar', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConArqueo('arqueo-qr');

    stockParaArqueo($variante, $almacen, $cajero, $ocasional, '1');
    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '0',
    ], $cajero);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '100']],
    ], $cajero);

    expect(fn () => app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => mediosSegunArqueo($sesion->fresh(), [
            MetodoPago::QrExterno->value => '0',
        ]),
    ], $cajero))->toThrow(ValidationException::class);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => mediosSegunArqueo($sesion->fresh()),
    ], $cajero);

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Cerrada)
        ->and($cerrada->monto_cierre_teorico)->toBe('0.00')
        ->and($cerrada->diferencia)->toBe('0.00')
        ->and(CajaSesionMedio::query()->where('caja_sesion_id', $cerrada->id)->count())->toBe(4)
        ->and(CajaSesionMedio::query()
            ->where('caja_sesion_id', $cerrada->id)
            ->where('metodo_pago', MetodoPago::QrExterno)
            ->value('monto_declarado'))->toBe('100.00');
});

it('exige justificación si el efectivo no calza aunque el qr sí', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConArqueo('arqueo-mix');

    stockParaArqueo($variante, $almacen, $cajero, $ocasional, '1');
    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajero);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [
            ['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '40'],
            ['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '60'],
        ],
    ], $cajero);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => mediosSegunArqueo($sesion->fresh(), [
            MetodoPago::Efectivo->value => '45',
        ]),
        'justificacion_diferencia' => 'Faltó un billete de cinco.',
    ], $cajero);

    expect($cerrada->diferencia)->toBe('-5.00')
        ->and($cerrada->justificacion_diferencia)->toBe('Faltó un billete de cinco.')
        ->and(CajaSesionMedio::query()
            ->where('caja_sesion_id', $cerrada->id)
            ->where('metodo_pago', MetodoPago::Efectivo)
            ->value('diferencia'))->toBe('-5.00');
});
