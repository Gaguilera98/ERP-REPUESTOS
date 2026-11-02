<?php

use App\Caja\AbrirCajaSesion;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Enums\TipoCliente;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\Cliente;
use App\Models\ClienteDocumento;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;
use App\Models\Venta;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CompraFlash;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

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

it('aplica descuento de la venta en porcentaje', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-pct');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Porcentaje->value,
        'valor_descuento' => '10',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '90']],
    ], $cajero)['venta'];

    expect($venta->subtotal)->toBe('100.00')
        ->and($venta->tipo_descuento)->toBe(TipoDescuento::Porcentaje)
        ->and($venta->valor_descuento)->toBe('10.00')
        ->and($venta->descuento)->toBe('10.00')
        ->and($venta->total)->toBe('90.00');
});

it('aplica descuento de la venta en importe', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-imp');

    NegocioConfiguracion::query()->update(['descuento_max_importe' => '20']);
    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Importe->value,
        'valor_descuento' => '15',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '85']],
    ], $cajero)['venta'];

    expect($venta->tipo_descuento)->toBe(TipoDescuento::Importe)
        ->and($venta->valor_descuento)->toBe('15.00')
        ->and($venta->descuento)->toBe('15.00')
        ->and($venta->total)->toBe('85.00');
});

it('exige supervisor si el descuento de la venta pasa el tope', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-tope');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Porcentaje->value,
        'valor_descuento' => '20',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '80']],
    ], $cajero))->toThrow(ValidationException::class);
});

it('no deja descuento de venta mayor al total', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-venta-neg');

    NegocioConfiguracion::query()->update(['descuento_max_importe' => '200']);
    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'tipo_descuento' => TipoDescuento::Importe->value,
        'valor_descuento' => '150',
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '0']],
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
        ->and($pagos[1]->caja_sesion_id)->toBe($pagos[0]->caja_sesion_id)
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

it('sugiere productos al escribir y los agrega al carrito desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('busqueda');

    $variante->productoMaestro->update(['nombre' => 'CARBURADOR TOYOTA COROLLA 83']);
    $variante->update(['sku' => '21000-134200', 'codigo_barras' => '779000111222']);

    cargarStock($variante, $almacen, $cajero, $ocasional, '4');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '20'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', 'carbura')
        ->assertSet('resultadosBusqueda.0.sku', '21000-134200')
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('busqueda', '')
        ->assertCount('lineas', 1)
        ->assertSet('lineas.0.sku', '21000-134200');
});

it('muestra el símbolo de la unidad de stock en la búsqueda y el carrito', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('unidad-stock');

    $kilo = UnidadMedida::query()->where('simbolo', 'kg')->firstOrFail();
    $variante->productoMaestro->update(['unidad_base_id' => $kilo->id]);

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', $variante->sku)
        ->assertSet('resultadosBusqueda.0.unidad_simbolo', 'kg')
        ->assertSee('kg')
        ->call('seleccionarResultado', $variante->id)
        ->assertSet('lineas.0.unidad_simbolo', 'kg')
        ->set('lineas.0.cantidad', '0.7')
        ->assertSee('0.7 kg')
        ->call('cambiarCantidad', 0, 1)
        ->assertSet('lineas.0.cantidad', '1.7')
        ->call('cambiarCantidad', 0, -1)
        ->assertSet('lineas.0.cantidad', '0.7')
        ->call('compactarCantidadVisible', 0)
        ->assertSet('lineas.0.cantidad', '0.7');
});

it('agrega por código de barras exacto con Enter en el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('escaneo');

    $variante->update(['codigo_barras' => '7896543210987']);

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', '7896543210987')
        ->call('buscarYAgregar')
        ->assertCount('lineas', 1)
        ->assertSet('lineas.0.producto_variante_id', $variante->id)
        ->assertSet('busqueda', '')
        ->assertSet('resultadosBusqueda', []);
});

it('no lista el catálogo al abrir la búsqueda avanzada vacía', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('avanzada-vacia');

    cargarStock($variante, $almacen, $cajero, $ocasional, '2');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirBusquedaAvanzada')
        ->assertSet('mostrarBusquedaAvanzada', true)
        ->assertSet('resultadosAvanzados', [])
        ->assertSet('totalAvanzados', 0)
        ->assertSee('Escribí un SKU, nombre o elegí un filtro')
        ->set('busquedaAvanzada', $variante->sku)
        ->assertCount('resultadosAvanzados', 1)
        ->assertSet('resultadosAvanzados.0.sku', $variante->sku);
});

it('abre el modal avanzado y filtra rodamientos por sello y stock del almacén', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'ocasional' => $ocasional] = negocioConMostrador('avanzada');

    $otroAlmacen = Almacen::factory()->create(['nombre' => 'Fondo']);
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    $producto = ProductoMaestro::factory()->create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Rodamiento 6204',
    ]);
    $conStock = ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6305-2RS',
        'precio_venta' => '15.00',
        'atributos_json' => ['sello' => '2RS'],
    ]);
    $sinStock = ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6305-ZZ',
        'precio_venta' => '16.00',
        'atributos_json' => ['sello' => 'ZZ'],
    ]);

    cargarStock($conStock, $almacen, $cajero, $ocasional, '3');
    cargarStock($sinStock, $otroAlmacen, $cajero, $ocasional, '8');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->set('busqueda', '6305')
        ->call('abrirBusquedaAvanzada')
        ->assertSet('mostrarBusquedaAvanzada', true)
        ->assertSet('busquedaAvanzada', '6305')
        ->assertSet('almacenId', $almacen->id)
        ->set('filtroCategoriaId', $categoria->id)
        ->assertSet('atributosVisibles', [])
        ->call('agregarFiltroAtributo', 'sello')
        ->assertSet('atributosVisibles', ['sello'])
        ->set('filtrosAtributos.sello', '2RS');

    expect(collect($pagina->get('resultadosAvanzados'))->pluck('sku')->all())->toBe(['6305-2RS'])
        ->and($pagina->get('resultadosAvanzados')[0]['sin_stock'])->toBeFalse();

    $pagina->set('filtroDisponibilidad', 'sin_stock')
        ->set('filtrosAtributos.sello', 'ZZ');

    expect(collect($pagina->get('resultadosAvanzados'))->pluck('sku')->all())->toBe(['6305-ZZ'])
        ->and($pagina->get('resultadosAvanzados')[0]['sin_stock'])->toBeTrue();

    $pagina->call('seleccionarResultado', $sinStock->id)
        ->assertSet('mostrarFlash', true)
        ->assertSet('flashVarianteId', $sinStock->id)
        ->assertSet('mostrarBusquedaAvanzada', false);
});

it('sugiere clientes por nombre o documento y los selecciona en el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('clientes-buscar');

    $juan = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Juan Carlos',
        'apellidos' => 'Pérez',
        'telefono' => '71345678',
    ]);
    $ana = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Ana',
        'apellidos' => 'Pérez Morales',
    ]);
    $otro = Cliente::factory()->personaNatural()->create([
        'nombres' => 'Luis',
        'apellidos' => 'Gómez',
    ]);

    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->firstOrFail();
    ClienteDocumento::factory()->create([
        'cliente_id' => $juan->id,
        'tipo_documento_identidad_id' => $nit->id,
        'valores_json' => ['numero' => '7845123'],
    ]);

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->assertSet('mostrarCliente', true)
        ->set('busquedaCliente', 'perez');

    expect(collect($pagina->get('resultadosCliente'))->pluck('id')->all())
        ->toContain($juan->id)
        ->toContain($ana->id)
        ->not->toContain($otro->id);

    $pagina->set('busquedaCliente', '7845123');

    expect(collect($pagina->get('resultadosCliente'))->pluck('id')->all())->toBe([$juan->id]);

    $pagina->call('seleccionarCliente', $juan->id)
        ->assertSet('clienteId', $juan->id)
        ->assertSet('mostrarCliente', false);
});

it('registra un cliente rápido desde el mostrador y lo deja seleccionado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-nuevo');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->set('busquedaCliente', 'Luis Pérez Gómez')
        ->call('abrirRegistroCliente')
        ->assertSet('mostrandoRegistroCliente', true)
        ->assertSet('clienteTipo', 'persona_natural')
        ->assertSet('clienteNombres', 'Luis Pérez')
        ->assertSet('clienteApellidos', 'Gómez')
        ->set('clienteDocumento', '8765432')
        ->set('clienteTelefono', '71345678')
        ->set('clienteEmail', 'luis@example.com')
        ->call('registrarClienteRapido')
        ->assertSet('mostrarCliente', false);

    $cliente = Cliente::query()->where('email', 'luis@example.com')->first();

    expect($cliente)->not->toBeNull()
        ->and($cliente->tipo_cliente)->toBe(TipoCliente::PersonaNatural)
        ->and($cliente->nombres)->toBe('Luis Pérez')
        ->and($cliente->apellidos)->toBe('Gómez')
        ->and($cliente->telefono)->toBe('71345678')
        ->and($pagina->get('clienteId'))->toBe($cliente->id)
        ->and($cliente->documentos)->toHaveCount(1)
        ->and($cliente->documentos->first()->valores_json['numero'])->toBe('8765432');
});

it('selecciona el cliente existente si el documento ya está registrado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-existe');

    $existente = Cliente::factory()->personaNatural()->create([
        'nombres' => 'María',
        'apellidos' => 'Pérez',
        'telefono' => '70000000',
    ]);
    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->firstOrFail();
    ClienteDocumento::factory()->create([
        'cliente_id' => $existente->id,
        'tipo_documento_identidad_id' => $nit->id,
        'valores_json' => ['numero' => '9988776'],
    ]);

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteNombres', 'Otra')
        ->set('clienteApellidos', 'Persona')
        ->set('clienteDocumento', '9988776')
        ->set('clienteTelefono', '71111111')
        ->call('registrarClienteRapido')
        ->assertSet('clienteId', $existente->id);

    expect(Cliente::query()->count())->toBe(1);
});

it('no registra un cliente rápido si falta el apellido', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-incompleto');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteNombres', 'Luis')
        ->set('clienteDocumento', '1112223')
        ->set('clienteTelefono', '70000001')
        ->call('registrarClienteRapido')
        ->assertSet('mostrandoRegistroCliente', true)
        ->assertSet('clienteId', null);

    expect(Cliente::query()->count())->toBe(0);
});

it('aplica el descuento de venta desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConMostrador('desc-mostrador');

    cargarStock($variante, $almacen, $cajero, $ocasional, '1');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->assertSee('Agregar descuento')
        ->assertDontSee('Descuento de la venta')
        ->call('seleccionarResultado', $variante->id)
        ->call('mostrarCampoDescuentoVenta')
        ->assertSet('mostrarDescuentoVenta', true)
        ->assertSet('tipoDescuentoVenta', TipoDescuento::Porcentaje->value)
        ->assertSee('Descuento de la venta')
        ->set('valorDescuentoVenta', '10')
        ->set('pagos.0.monto', '90')
        ->call('completar')
        ->assertSet('ultimoCodigo', 'VNT-000001')
        ->assertSet('mostrarDescuentoVenta', false);

    $venta = Venta::query()->first();

    expect($venta)->not->toBeNull()
        ->and($venta->tipo_descuento)->toBe(TipoDescuento::Porcentaje)
        ->and($venta->descuento)->toBe('10.00')
        ->and($venta->total)->toBe('90.00');
});

it('registra una empresa rápida desde el mostrador', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConMostrador('cliente-empresa');

    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '10'], $cajero);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')
        ->test(MostradorDelNegocio::class)
        ->call('abrirCliente')
        ->call('abrirRegistroCliente')
        ->set('clienteTipo', 'empresa')
        ->set('clienteRazonSocial', 'Taller Norte SRL')
        ->set('clienteDocumento', '102938475')
        ->set('clienteTelefono', '33334444')
        ->call('registrarClienteRapido')
        ->assertSet('mostrarCliente', false);

    $cliente = Cliente::query()->where('razon_social', 'Taller Norte SRL')->first();

    expect($cliente)->not->toBeNull()
        ->and($cliente->tipo_cliente)->toBe(TipoCliente::Empresa)
        ->and($cliente->nombres)->toBeNull()
        ->and($pagina->get('clienteId'))->toBe($cliente->id)
        ->and($cliente->documentos->first()->valores_json['numero'])->toBe('102938475');
});
