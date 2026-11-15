<?php

use App\Catalogo\PublicarTipoCambio;
use App\Compras\AnularOrdenCompra;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoOrdenCompra;
use App\Enums\MagnitudUnidad;
use App\Enums\ModoMoneda;
use App\Enums\UsoUnidad;
use App\Exceptions\Compras\KardexInmutableException;
use App\Exceptions\Compras\ProveedorOcasionalProtegidoException;
use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\CreateOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\EditOrdenCompra;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ListOrdenCompras;
use App\Filament\Negocio\Resources\OrdenCompras\Pages\ViewOrdenCompra;
use App\Models\Almacen;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\ProductoUnidad;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Trabajador;
use App\Models\UnidadMedida;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * @return array{supervisor: TenantUser, almacen: Almacen, variante: ProductoVariante, ocasional: Proveedor}
 */
function negocioConPieza(string $dominio = 'norte'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('administrador');
    $almacen = Almacen::factory()->create(['nombre' => 'Depósito']);
    $variante = ProductoVariante::factory()->create([
        'sku' => '6204-2RS',
        'precio_venta' => '25.50',
        'precio_costo' => 0,
    ]);
    $variante->productoMaestro->update(['nombre' => 'Rodamiento']);
    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('supervisor', 'almacen', 'variante', 'ocasional');
}

it('crea un solo proveedor ocasional por negocio y no deja borrarlo ni repetirlo', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    $ocasional = Proveedor::query()->where('es_ocasional', true)->first();

    expect($ocasional)->not->toBeNull()
        ->and($ocasional->nombre_empresa)->toBe(Proveedor::OCASIONAL)
        ->and(Proveedor::query()->where('es_ocasional', true)->count())->toBe(1);

    expect(fn () => Proveedor::factory()->create([
        'nombre_empresa' => 'Otro',
        'es_ocasional' => true,
    ]))->toThrow(QueryException::class);

    expect(fn () => $ocasional->delete())->toThrow(ProveedorOcasionalProtegidoException::class);

    $ocasional->update(['nombre_empresa' => 'Cambiado', 'nit' => '100']);

    expect($ocasional->fresh()->nombre_empresa)->toBe(Proveedor::OCASIONAL)
        ->and($ocasional->fresh()->nit)->toBe('100');

    expect(fn () => DB::connection('pool_shared_1')->table('proveedores')->where('id', $ocasional->id)->update([
        'nombre_empresa' => 'Cambiado',
    ]))->toThrow(QueryException::class);

    tenancy()->initialize($sur);

    expect(Proveedor::query()->where('es_ocasional', true)->count())->toBe(1)
        ->and(Proveedor::query()->where('es_ocasional', true)->first()?->id)->not->toBe($ocasional->id);
});

it('permite NIT vacío repetido y rechaza el mismo NIT dentro del negocio', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    Proveedor::factory()->create(['nit' => null]);
    Proveedor::factory()->create(['nit' => '']);

    expect(Proveedor::query()->whereNull('nit')->count())->toBe(3);

    $conNit = Proveedor::factory()->create(['nit' => '123456']);

    expect($conNit->nit)->toBe('123456')
        ->and(fn () => Proveedor::factory()->create(['nit' => '123456']))->toThrow(QueryException::class);

    tenancy()->initialize($sur);

    expect(Proveedor::factory()->create(['nit' => '123456'])->nit)->toBe('123456');
});

it('exige apellidos del trabajador y del contacto', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    expect(fn () => Trabajador::factory()->create(['apellidos' => null]))->toThrow(QueryException::class);
    expect(fn () => ProveedorContacto::factory()->create(['apellidos' => null]))->toThrow(QueryException::class);
});

it('deja un solo contacto principal', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);

    $proveedor = Proveedor::factory()->create();
    $ana = ProveedorContacto::factory()->create([
        'proveedor_id' => $proveedor->id,
        'nombres' => 'Ana',
        'es_principal' => true,
    ]);
    $luis = ProveedorContacto::factory()->create([
        'proveedor_id' => $proveedor->id,
        'nombres' => 'Luis',
        'es_principal' => true,
    ]);

    expect($ana->fresh()->es_principal)->toBeFalse()
        ->and($luis->fresh()->es_principal)->toBeTrue();
});

it('guarda la compra sin mover stock ni el precio y solo recibe si confirman el costo', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'fecha_emision' => now(),
        'estado' => EstadoOrdenCompra::Completada,
        'es_compra_flash' => true,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '200',
        ]],
    ], $supervisor);

    $stock = InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->first();

    expect($orden->estado)->toBe(EstadoOrdenCompra::Pendiente)
        ->and($orden->es_compra_flash)->toBeFalse()
        ->and($orden->detalles)->toHaveCount(1)
        ->and($orden->detalles->first()->costo_unitario)->toBe('200.0000')
        ->and($orden->total)->toBe('800.00')
        ->and($variante->fresh()->precio_venta)->toBe('25.50')
        ->and($variante->fresh()->precio_costo)->toBe('0.0000')
        ->and($stock->cantidad_actual)->toBe('0.0000')
        ->and(MovimientoInventario::query()->count())->toBe(0);

    expect(fn () => app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => 4],
    ], $supervisor, false))->toThrow(ValidationException::class);

    expect($variante->fresh()->precio_costo)->toBe('0.0000')
        ->and(MovimientoInventario::query()->count())->toBe(0);

    $otro = Proveedor::factory()->create();
    $contactoAjeno = ProveedorContacto::factory()->create(['proveedor_id' => $otro->id]);

    expect(fn () => app(GuardarOrdenCompra::class)->actualizar($orden, [
        'proveedor_id' => $ocasional->id,
        'proveedor_contacto_id' => $contactoAjeno->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '200',
        ]],
    ], $supervisor))->toThrow(ValidationException::class);
});

it('al recibir recalcula el costo promedio de toda la pieza, escribe kardex y no toca el precio de venta', function () {
    ['supervisor' => $supervisor, 'almacen' => $deposito, 'variante' => $variante] = negocioConPieza();
    $estante = Almacen::factory()->create(['nombre' => 'Estante']);
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'SKF']);
    $guardar = app(GuardarOrdenCompra::class);
    $recibir = app(RecibirMercaderia::class);

    $primera = $guardar->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $deposito->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '10',
        ]],
    ], $supervisor);

    $recibir->recibir($primera, [
        ['detalle_id' => $primera->detalles->first()->id, 'cantidad' => 4],
    ], $supervisor, true);

    $segunda = $guardar->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $estante->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '30',
        ]],
    ], $supervisor);

    $recibir->recibir($segunda, [
        ['detalle_id' => $segunda->detalles->first()->id, 'cantidad' => 4],
    ], $supervisor, true);

    $variante->refresh();
    $movimiento = MovimientoInventario::query()->where('referencia_id', $segunda->id)->first();

    expect($variante->precio_costo)->toBe('20.0000')
        ->and($variante->precio_venta)->toBe('25.50')
        ->and($variante->precio_mayor)->toBeNull()
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $deposito->id)->first()->cantidad_actual)->toBe('4.0000')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $estante->id)->first()->cantidad_actual)->toBe('4.0000')
        ->and($movimiento)->not->toBeNull()
        ->and($movimiento->stock_previo)->toBe('0.0000')
        ->and($movimiento->stock_posterior)->toBe('4.0000')
        ->and($movimiento->costo_unitario)->toBe('30.0000')
        ->and($movimiento->costo_promedio_resultante)->toBe('20.0000')
        ->and($movimiento->user_id)->toBe($supervisor->id)
        ->and($movimiento->referencia_tipo)->toBe(MovimientoInventario::REFERENCIA_ORDEN_COMPRA)
        ->and($segunda->fresh()->estado)->toBe(EstadoOrdenCompra::Completada);

    expect(fn () => $movimiento->update(['costo_unitario' => '1']))->toThrow(KardexInmutableException::class);
    expect(fn () => $movimiento->delete())->toThrow(KardexInmutableException::class);
    expect(fn () => DB::connection('pool_shared_1')->table('movimientos_inventario')->where('id', $movimiento->id)->update([
        'costo_unitario' => 1,
    ]))->toThrow(QueryException::class);

    $parcial = $guardar->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $deposito->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 10,
            'costo_unitario' => '8',
        ]],
    ], $supervisor);
    $detalle = $parcial->detalles->first();

    $recibir->recibir($parcial, [
        ['detalle_id' => $detalle->id, 'cantidad' => 4],
    ], $supervisor, true);

    expect($parcial->fresh()->estado)->toBe(EstadoOrdenCompra::RecibidaParcial)
        ->and($detalle->fresh()->cantidad_recibida)->toBe('4.0000')
        ->and($detalle->fresh()->costo_unitario)->toBe('8.0000');

    expect(fn () => $guardar->actualizar($parcial, [
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $estante->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 10,
            'costo_unitario' => '1',
        ]],
    ]))->toThrow(ValidationException::class);

    expect($detalle->fresh()->costo_unitario)->toBe('8.0000')
        ->and($parcial->fresh()->proveedor_id)->toBe($proveedor->id);

    $otroProveedor = Proveedor::factory()->create(['nombre_empresa' => 'Otro']);

    expect(fn () => $guardar->actualizar($parcial->fresh(), [
        'proveedor_id' => $otroProveedor->id,
        'almacen_destino_id' => $deposito->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 1,
            'costo_unitario' => '1',
        ]],
    ]))->toThrow(ValidationException::class);

    expect($parcial->fresh()->proveedor_id)->toBe($proveedor->id)
        ->and($detalle->fresh()->costo_unitario)->toBe('8.0000');

    expect(fn () => $recibir->recibir($parcial->fresh(), [
        ['detalle_id' => $detalle->id, 'cantidad' => 7],
    ], $supervisor, true))->toThrow(ValidationException::class);

    $recibir->recibir($parcial->fresh(), [
        ['detalle_id' => $detalle->id, 'cantidad' => 6],
    ], $supervisor, true);

    expect($parcial->fresh()->estado)->toBe(EstadoOrdenCompra::Completada)
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $deposito->id)->first()->cantidad_actual)->toBe('14.0000');

    $pendiente = $guardar->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $deposito->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 1,
            'costo_unitario' => '5',
        ]],
    ], $supervisor);
    $antes = MovimientoInventario::query()->count();

    app(AnularOrdenCompra::class)->anular($pendiente);

    expect($pendiente->fresh()->estado)->toBe(EstadoOrdenCompra::Anulada)
        ->and(MovimientoInventario::query()->count())->toBe($antes)
        ->and(fn () => app(AnularOrdenCompra::class)->anular($pendiente->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => $recibir->recibir($pendiente->fresh(), [
            ['detalle_id' => $pendiente->detalles->first()->id, 'cantidad' => 1],
        ], $supervisor, true))->toThrow(ValidationException::class);
});

it('rechaza un producto de otro negocio', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    $almacen = Almacen::factory()->create();
    $variante = ProductoVariante::factory()->create();

    tenancy()->initialize($sur);
    $ajeno = TenantUser::factory()->create()->assignRole('administrador');
    $almacenSur = Almacen::factory()->create();
    $proveedorSur = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    expect(fn () => app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $proveedorSur->id,
        'almacen_destino_id' => $almacenSur->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 1,
            'costo_unitario' => '10',
        ]],
    ], $ajeno))->toThrow(ValidationException::class);

    expect(OrdenCompra::query()->count())->toBe(0)
        ->and(ProductoVariante::query()->find($variante->id))->toBeNull();

    tenancy()->initialize($norte);

    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->first()->cantidad_actual)->toBe('0.0000');
});

it('muestra el costo por unidad y no escribe kardex hasta confirmarlo', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('vendedor');

    expect($cajero->can('create', OrdenCompra::class))->toBeFalse()
        ->and($supervisor->can('create', OrdenCompra::class))->toBeTrue()
        ->and($cajero->can('viewAny', Proveedor::class))->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateOrdenCompra::class)
        ->fillForm([
            'proveedor_id' => $ocasional->id,
            'almacen_destino_id' => $almacen->id,
            'fecha_emision' => now()->format('Y-m-d H:i:s'),
            'lineas' => [[
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => 4,
                'costo_unitario' => '200',
            ]],
        ])
        ->assertSee('BOB 800.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $orden = OrdenCompra::query()->first();
    $detalle = $orden->detalles->first();

    expect($orden)->not->toBeNull()
        ->and(MovimientoInventario::query()->count())->toBe(0);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->mountTableAction('recibir', $orden)
        ->assertMountedActionModalSee('Costo por pza: BOB 200 (doscientos bolivianos).')
        ->assertMountedActionModalSee('Confirmo estos costos por unidad');

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->callTableAction('recibir', $orden, data: [
            'cantidad_'.$detalle->id => 4,
            'confirmo_costos' => false,
        ])
        ->assertHasTableActionErrors(['confirmo_costos']);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->callTableAction('recibir', $orden, data: [
            'cantidad_'.$detalle->id => 0,
            'confirmo_costos' => true,
        ])
        ->assertHasTableActionErrors(['cantidad_'.$detalle->id]);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->callTableAction('recibir', $orden, data: [
            'cantidad_'.$detalle->id => '0.5',
            'confirmo_costos' => true,
        ])
        ->assertHasTableActionErrors(['cantidad_'.$detalle->id]);

    expect(fn () => app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $detalle->id, 'cantidad' => '0.5'],
    ], $supervisor, true))->toThrow(ValidationException::class);

    expect(MovimientoInventario::query()->count())->toBe(0)
        ->and($variante->fresh()->precio_venta)->toBe('25.50');

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->callTableAction('recibir', $orden, data: [
            'cantidad_'.$detalle->id => 4,
            'confirmo_costos' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($variante->fresh()->precio_costo)->toBe('200.0000')
        ->and($variante->fresh()->precio_venta)->toBe('25.50')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->first()->cantidad_actual)->toBe('4.0000')
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenCompra::Completada);

    tenancy()->end();

    $this->actingAs($cajero, 'tenant')
        ->get('http://norte.localhost/negocio/compras/create')
        ->assertForbidden();
});

it('guarda cédula y celular y no repite la cédula dentro del negocio', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);

    $ana = Trabajador::factory()->create([
        'cedula' => ' 1234567 ',
        'celular' => ' 70000000 ',
    ]);

    expect($ana->cedula)->toBe('1234567')
        ->and($ana->celular)->toBe('70000000');

    Trabajador::factory()->create(['cedula' => null, 'celular' => '']);
    Trabajador::factory()->create(['cedula' => '']);

    expect(fn () => Trabajador::factory()->create(['cedula' => '1234567']))->toThrow(QueryException::class);

    tenancy()->initialize($sur);

    expect(Trabajador::factory()->create(['cedula' => '1234567'])->cedula)->toBe('1234567');
});

it('usa el costo final anotado para el total y el costo recibido', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante] = negocioConPieza();
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'SKF']);

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '200',
        ]],
    ], $supervisor);

    expect($orden->subtotal)->toBe('800.00')
        ->and($orden->total)->toBe('800.00');

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => 4],
    ], $supervisor, true);

    expect($variante->fresh()->precio_costo)->toBe('200.0000');
});

it('muestra y guarda el total de los productos en el formulario de compra', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateOrdenCompra::class)
        ->fillForm([
            'proveedor_id' => $ocasional->id,
            'almacen_destino_id' => $almacen->id,
            'fecha_emision' => now()->format('Y-m-d H:i:s'),
            'lineas' => [[
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => 4,
                'costo_unitario' => '200',
            ]],
        ])
        ->assertSee('BOB 800.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $orden = OrdenCompra::query()->first();

    expect($orden->subtotal)->toBe('800.00')
        ->and($orden->total)->toBe('800.00');

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => 4],
    ], $supervisor, true);

    expect($variante->fresh()->precio_costo)->toBe('200.0000');
});

it('guarda el costo en USD y usa la cotización de la compra para cada recepción', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();
    NegocioConfiguracion::query()->firstOrFail()->update(['modo_moneda' => ModoMoneda::ConDolar]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $publicacion = app(PublicarTipoCambio::class);
    $cambioOriginal = $publicacion->publicar('10.5', $publicacion->previsualizar('10.5')['firma'], $supervisor);

    Livewire::actingAs($supervisor, 'tenant')->test(CreateOrdenCompra::class)
        ->fillForm([
            'proveedor_id' => $ocasional->id, 'almacen_destino_id' => $almacen->id,
            'lineas' => [[
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => 4, 'moneda' => 'USD', 'costo_unitario' => '15',
            ]],
        ])
        ->assertSee('Equivale a BOB 157.50 por unidad.')
        ->assertSee('BOB 630.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $orden = OrdenCompra::query()->sole();
    $detalle = $orden->detalles()->sole();
    $recibir = app(RecibirMercaderia::class);
    $recibir->recibir($orden, [['detalle_id' => $detalle->id, 'cantidad' => 2]], $supervisor, true);
    $publicacion->publicar('12', $publicacion->previsualizar('12')['firma'], $supervisor);
    $recibir->recibir($orden, [['detalle_id' => $detalle->id, 'cantidad' => 2]], $supervisor, true);

    expect($detalle->refresh()->costo_unitario_origen)->toBe('15.0000')
        ->and($detalle->tipo_cambio_valor)->toBe('10.5000')
        ->and((int) $detalle->tipo_cambio_id)->toBe($cambioOriginal->id)
        ->and($detalle->costo_unitario)->toBe('157.5000')
        ->and($detalle->cantidad_recibida)->toBe('4.0000');
    expect($orden->refresh()->total)->toBe('630.00');
    expect($variante->refresh()->precio_costo)->toBe('157.5000')
        ->and($variante->precio_venta)->toBe('25.50');

    Livewire::actingAs($supervisor, 'tenant')->test(ViewOrdenCompra::class, ['record' => $orden->getRouteKey()])
        ->assertSee('BOB 157.50')
        ->assertSee('USD 15 por pza')
        ->assertSee('Cambio de la compra · 1 USD = BOB 10.50')
        ->assertSee('BOB 630.00');
});

it('al recibir una parte oculta anular y eliminar en la ficha', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 10,
            'costo_unitario' => '25',
        ]],
    ], $supervisor);
    $detalle = $orden->detalles->first();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditOrdenCompra::class, ['record' => $orden->getRouteKey()])
        ->assertActionVisible('anular')
        ->assertActionVisible('delete')
        ->callAction('recibir', data: [
            'cantidad_'.$detalle->id => 9,
            'confirmo_costos' => true,
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect(OrdenCompraResource::getUrl('view', ['record' => $orden]));

    expect($orden->fresh()->estado)->toBe(EstadoOrdenCompra::RecibidaParcial);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ViewOrdenCompra::class, ['record' => $orden->getRouteKey()])
        ->assertSee('Recepción parcial')
        ->assertSee('Esta compra quedó recibida a medias.')
        ->assertSee('Llegaron 9 de 10 pza.')
        ->assertActionVisible('recibir')
        ->assertFormFieldDisabled('lineas')
        ->assertFormFieldDisabled('proveedor_id');
});

it('muestra ver en una compra recibida y edit en una pendiente', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();
    $guardar = app(GuardarOrdenCompra::class);

    $pendiente = $guardar->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 2,
            'costo_unitario' => '10',
        ]],
    ], $supervisor);

    $recibida = $guardar->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 2,
            'costo_unitario' => '10',
        ]],
    ], $supervisor);

    app(RecibirMercaderia::class)->recibir($recibida, [
        ['detalle_id' => $recibida->detalles->first()->id, 'cantidad' => 2],
    ], $supervisor, true);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListOrdenCompras::class)
        ->assertTableActionVisible('edit', $pendiente)
        ->assertTableActionHidden('view', $pendiente)
        ->assertTableActionHidden('edit', $recibida)
        ->assertTableActionVisible('view', $recibida);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditOrdenCompra::class, ['record' => $recibida->getRouteKey()])
        ->assertRedirect(OrdenCompraResource::getUrl('view', ['record' => $recibida]));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ViewOrdenCompra::class, ['record' => $recibida->getRouteKey()])
        ->assertFormFieldDisabled('proveedor_id')
        ->assertActionHidden('recibir');
});

it('al editar conserva los datos de la compra y recalcula el total', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante] = negocioConPieza();
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'SKF']);
    $otro = ProductoVariante::factory()->create([
        'sku' => '6205-2RS',
        'precio_venta' => '10.00',
        'precio_costo' => 0,
    ]);

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 4,
            'costo_unitario' => '200',
        ]],
    ], $supervisor);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditOrdenCompra::class, ['record' => $orden->getRouteKey()])
        ->assertFormSet(['proveedor_id' => $proveedor->id]);

    $orden = app(GuardarOrdenCompra::class)->actualizar($orden, [
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [
            [
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => 4,
                'costo_unitario' => '200',
            ],
            [
                'producto_variante_id' => $otro->id,
                'cantidad_pedida' => 1,
                'costo_unitario' => '200',
            ],
        ],
    ], $supervisor);

    expect($orden->subtotal)->toBe('1000.00')
        ->and($orden->total)->toBe('1000.00');
});

it('recalcula y guarda el total al agregar un producto', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioConPieza();
    $otro = ProductoVariante::factory()->create([
        'sku' => '6205-2RS',
        'precio_venta' => '10.00',
        'precio_costo' => 0,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateOrdenCompra::class)
        ->fillForm([
            'proveedor_id' => $ocasional->id,
            'almacen_destino_id' => $almacen->id,
            'fecha_emision' => now()->format('Y-m-d H:i:s'),
            'lineas' => [[
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => 4,
                'costo_unitario' => '200',
            ]],
        ])
        ->assertSee('BOB 800.00')
        ->fillForm([
            'lineas' => [
                [
                    'producto_variante_id' => $variante->id,
                    'cantidad_pedida' => 4,
                    'costo_unitario' => '200',
                ],
                [
                    'producto_variante_id' => $otro->id,
                    'cantidad_pedida' => 1,
                    'costo_unitario' => '200',
                ],
            ],
        ])
        ->assertSee('BOB 1000.00')
        ->call('create')
        ->assertHasNoFormErrors();

    $orden = OrdenCompra::query()->first();

    expect($orden->subtotal)->toBe('1000.00')
        ->and($orden->total)->toBe('1000.00');
});

it('convierte una caja a piezas y deja el costo por pieza', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante] = negocioConPieza();
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'SKF']);
    $caja = UnidadMedida::factory()->create([
        'nombre' => 'Caja',
        'simbolo' => 'cja',
        'magnitud' => MagnitudUnidad::Conteo,
        'uso' => UsoUnidad::Cantidad,
        'factor_a_referencia' => '1',
        'decimales' => 0,
    ]);

    ProductoUnidad::query()->create([
        'producto_maestro_id' => $variante->producto_maestro_id,
        'unidad_medida_id' => $caja->id,
        'factor_a_base' => '100',
    ]);

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $proveedor->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $caja->id,
            'cantidad_pedida' => 2,
            'costo_unitario' => '50',
        ]],
    ], $supervisor);

    $detalle = $orden->detalles->first();

    expect($detalle->factor_a_base)->toBe('100.000000')
        ->and($detalle->cantidad_base)->toBe('200.0000')
        ->and($detalle->costo_anotado)->toBe('50.0000')
        ->and($detalle->costo_unitario)->toBe('0.5000')
        ->and($detalle->subtotal_linea)->toBe('100.00');

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $detalle->id, 'cantidad' => 2],
    ], $supervisor, true);

    expect($variante->fresh()->precio_costo)->toBe('0.5000')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $almacen->id)->first()->cantidad_actual)->toBe('200.0000');
});

it('borra las compras del negocio y deja las del otro', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    $supervisor = TenantUser::factory()->create()->assignRole('administrador');
    $almacen = Almacen::factory()->create();
    $variante = ProductoVariante::factory()->create(['precio_venta' => 10]);
    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'cantidad_pedida' => 2,
            'costo_unitario' => '5',
        ]],
    ], $supervisor);
    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => 2],
    ], $supervisor, true);
    tenancy()->end();

    tenancy()->initialize($sur);
    $proveedorSur = Proveedor::query()->where('es_ocasional', true)->firstOrFail();
    tenancy()->end();

    $norte->delete();

    $this->assertDatabaseMissing('ordenes_compra', ['id' => $orden->id], 'pool_shared_1');
    $this->assertDatabaseMissing('movimientos_inventario', ['referencia_id' => $orden->id], 'pool_shared_1');
    $this->assertDatabaseMissing('proveedores', ['tenant_id' => $norte->id], 'pool_shared_1');
    $this->assertDatabaseHas('proveedores', ['id' => $proveedorSur->id], 'pool_shared_1');
});
