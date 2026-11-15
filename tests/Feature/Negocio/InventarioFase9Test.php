<?php

use App\Caja\AbrirCajaSesion;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Filament\Negocio\Resources\AjusteInventarios\Pages\CreateAjusteInventario;
use App\Filament\Negocio\Resources\AjusteInventarios\Pages\ViewAjusteInventario;
use App\Filament\Negocio\Resources\Traslados\Pages\CreateTraslado;
use App\Filament\Negocio\Resources\Traslados\Pages\ViewTraslado;
use App\Inventario\AjustarInventario;
use App\Inventario\CorregirCostoCompra;
use App\Inventario\RegistrarMovimiento;
use App\Inventario\TrasladarProductos;
use App\Models\AjusteInventario;
use App\Models\Almacen;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Traslado;
use App\Ventas\CompletarVentaContado;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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

it('carga varios productos desde la planilla y guarda el cero explícito sin ajustar las filas vacías', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $primera, 'ocasional' => $ocasional] = negocioDeInventario('planilla-conteo');
    $segunda = ProductoVariante::factory()->for($primera->productoMaestro, 'productoMaestro')->create(['sku' => '6204-ZZ']);
    $pendiente = ProductoVariante::factory()->for($primera->productoMaestro, 'productoMaestro')->create(['sku' => '6204-RS']);
    cargarPiezas($primera, $almacen, $cajero, $ocasional, '5');
    cargarPiezas($pendiente, $almacen, $cajero, $ocasional, '4');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(CreateAjusteInventario::class)
        ->set('data.almacen_id', $almacen->id)
        ->set('busqueda', '6204')
        ->assertSee('Agregar los 3 visibles')
        ->call('agregarProductos', true)
        ->set('data.cantidades.'.$primera->id, '0')
        ->set('data.cantidades.'.$segunda->id, '1,25')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $conteo = AjusteInventario::query()->sole();
    expect($conteo->detalles()->pluck('cantidad_fisica', 'producto_variante_id')->all())
        ->toEqual([$primera->id => '0.0000', $segunda->id => '1.2500']);
    expect($primera->stocks()->where('almacen_id', $almacen->id)->sole()->cantidad_actual)->toBe('5.0000');

    Livewire::actingAs($supervisor, 'tenant')->test(ViewAjusteInventario::class, ['record' => $conteo->id])
        ->assertSee('Almacén bloqueado')
        ->assertSee('2 diferencias')
        ->callAction('cerrar')
        ->assertSee('Cerrado por');

    expect($primera->stocks()->where('almacen_id', $almacen->id)->sole()->cantidad_actual)->toBe('0.0000');
    expect($pendiente->stocks()->where('almacen_id', $almacen->id)->sole()->cantidad_actual)->toBe('4.0000');
});

it('frena la venta mientras el conteo está abierto y ajusta solo lo contado', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioDeInventario('conteo');
    cargarPiezas($variante, $almacen, $cajero, $ocasional, '10');
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $conteo = app(AjustarInventario::class)->abrirConteo($almacen->id, [
        ['producto_variante_id' => $variante->id, 'cantidad_fisica' => '8'],
    ], $cajero);

    expect(fn () => app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '100']],
    ], $cajero))->toThrow(ValidationException::class, RegistrarMovimiento::MENSAJE_CONTEO);

    app(AjustarInventario::class)->cerrarConteo($conteo, $supervisor);

    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('8.0000')
        ->and(ProductoVariante::query()->find($variante->id)->precio_costo)->toBe('40.0000')
        ->and(MovimientoInventario::query()->where('tipo_movimiento', 'ajuste')->sole()->cantidad)->toBe('2.0000');
});

it('exige escribir el cero y no cierra si la cantidad baja de lo reservado', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioDeInventario('conteo-reserva');
    cargarPiezas($variante, $almacen, $cajero, $ocasional, '5');
    $cliente = Cliente::factory()->personaNatural()->create();
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $cajero);
    $reserva = app(CompletarVentaContado::class)->reservar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'cliente_id' => $cliente->id,
        'vence_en' => now()->addDay()->toDateTimeString(),
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '3']],
        'pagos' => [['metodo_pago' => 'efectivo', 'monto' => '30']],
    ], $cajero)['venta'];

    expect(fn () => app(AjustarInventario::class)->abrirConteo($almacen->id, [
        ['producto_variante_id' => $variante->id, 'cantidad_fisica' => null],
    ], $cajero))->toThrow(ValidationException::class);

    $conteo = app(AjustarInventario::class)->abrirConteo($almacen->id, [
        ['producto_variante_id' => $variante->id, 'cantidad_fisica' => '1'],
    ], $cajero);

    expect(fn () => app(AjustarInventario::class)->cerrarConteo($conteo, $supervisor))
        ->toThrow(ValidationException::class, $reserva->codigo);
});

it('excluye el origen de los destinos y limpia el destino si pasa a ser el origen', function () {
    ['supervisor' => $supervisor, 'almacen' => $origen] = negocioDeInventario('destinos-traslado');
    $destino = Almacen::factory()->create(['nombre' => 'Depósito']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')->test(CreateTraslado::class)
        ->set('data.almacen_origen_id', $origen->id)
        ->assertFormFieldExists('almacen_destino_id', fn (Select $campo): bool => $campo->getOptions() === [$destino->id => 'Depósito'])
        ->set('data.almacen_destino_id', $origen->id)
        ->call('create')
        ->assertHasErrors('data.almacen_destino_id')
        ->assertSee('Elegí un almacén distinto del origen.')
        ->set('data.almacen_destino_id', $destino->id)
        ->set('data.almacen_origen_id', $destino->id)
        ->assertSet('data.almacen_destino_id', null)
        ->assertFormFieldExists('almacen_destino_id', fn (Select $campo): bool => $campo->getOptions() === [$origen->id => 'Estante']);

    expect(Traslado::query()->count())->toBe(0);
});

it('muestra en el buscador de traslados el saldo libre y la ubicación del almacén de origen', function () {
    ['supervisor' => $supervisor, 'almacen' => $origen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioDeInventario('buscador-traslado');
    $destino = Almacen::factory()->create();
    cargarPiezas($variante, $origen, $supervisor, $ocasional, '5');
    cargarPiezas($variante, $destino, $supervisor, $ocasional, '9');
    $stock = $variante->stocks()->where('almacen_id', $origen->id)->sole();
    $stock->forceFill(['cantidad_reservada' => '2', 'ubicacion' => 'Estante <A>'])->save();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')->test(CreateTraslado::class)
        ->set('data.almacen_origen_id', $origen->id)
        ->set('data.almacen_destino_id', $destino->id)
        ->assertFormFieldExists('productos', function (Select $campo) use ($variante): bool {
            $opcion = $campo->getSearchResults($variante->sku)[$variante->id];

            expect(strip_tags($opcion))->toContain('Disponible en origen 3 pza')
                ->and($opcion)->toContain('Estante &lt;A&gt;');

            return true;
        });
});

it('confirma un traslado de varios productos y muestra la observación y los movimientos vinculados', function () {
    ['supervisor' => $supervisor, 'almacen' => $origen, 'variante' => $primera, 'ocasional' => $ocasional] = negocioDeInventario('planilla-traslado');
    $destino = Almacen::factory()->create(['nombre' => 'Depósito']);
    $segunda = ProductoVariante::factory()->create(['sku' => '6205-ZZ']);
    cargarPiezas($primera, $origen, $supervisor, $ocasional, '5');
    cargarPiezas($segunda, $origen, $supervisor, $ocasional, '3');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pantalla = Livewire::actingAs($supervisor, 'tenant')->test(CreateTraslado::class)
        ->set('data.almacen_origen_id', $origen->id)
        ->set('data.almacen_destino_id', $destino->id)
        ->set('data.productos', [(string) $primera->id, (string) $segunda->id])
        ->assertSee('Disponible en origen')
        ->set('data.cantidades.'.$primera->id, '2')
        ->set('data.cantidades.'.$segunda->id, '4')
        ->set('data.observacion', 'Reposición del depósito')
        ->call('create')
        ->assertHasErrors('data.cantidades');

    expect(Traslado::query()->count())->toBe(0);
    expect($primera->stocks()->where('almacen_id', $origen->id)->sole()->cantidad_actual)->toBe('5.0000');

    $pantalla->set('data.cantidades.'.$segunda->id, '1,5')->call('create')->assertHasNoErrors()->assertRedirect();

    $traslado = Traslado::query()->sole();
    expect($traslado->detalles()->pluck('cantidad', 'producto_variante_id')->all())
        ->toEqual([$primera->id => '2.0000', $segunda->id => '1.5000']);

    Livewire::actingAs($supervisor, 'tenant')->test(ViewTraslado::class, ['record' => $traslado->id])
        ->assertSee($traslado->codigo())
        ->assertSee('Reposición del depósito')
        ->assertSee('Salida por traslado')
        ->assertSee('Entrada por traslado');
});

it('traslada de ida y de vuelta sin cambiar el costo', function () {
    ['supervisor' => $supervisor, 'almacen' => $origen, 'variante' => $variante, 'ocasional' => $ocasional, 'sucursal' => $sucursal] = negocioDeInventario('traslado');
    cargarPiezas($variante, $origen, $supervisor, $ocasional, '4');
    $destino = Almacen::factory()->create(['nombre' => 'Depósito']);
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $destino->id, 'es_principal' => false]);

    app(TrasladarProductos::class)->trasladar($origen->id, $destino->id, [
        ['producto_variante_id' => $variante->id, 'cantidad' => '3'],
    ], $supervisor);
    app(TrasladarProductos::class)->trasladar($destino->id, $origen->id, [
        ['producto_variante_id' => $variante->id, 'cantidad' => '3'],
    ], $supervisor);

    expect(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $origen->id)->sole()->cantidad_actual)->toBe('4.0000')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->where('almacen_id', $destino->id)->sole()->cantidad_actual)->toBe('0.0000')
        ->and(ProductoVariante::query()->find($variante->id)->precio_costo)->toBe('40.0000');
});

it('revalúa el promedio de lo que queda sin tocar el costo de la línea comprada', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioDeInventario('costo');
    $primera = recibir($variante, $almacen, $supervisor, $ocasional, '10', '100');
    recibir($variante, $almacen, $supervisor, $ocasional, '10', '20');

    expect(ProductoVariante::query()->find($variante->id)->precio_costo)->toBe('60.0000');

    app(CorregirCostoCompra::class)->corregir($primera, '10', 'El costo real era 10.', $supervisor);

    expect(ProductoVariante::query()->find($variante->id)->precio_costo)->toBe('15.0000')
        ->and($primera->refresh()->costo_unitario)->toBe('100.0000')
        ->and(InventarioStock::query()->where('producto_variante_id', $variante->id)->sole()->cantidad_actual)->toBe('20.0000');
});

it('usa el último costo corregido al recibir el resto y al corregir otra vez la misma línea', function () {
    ['supervisor' => $supervisor, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional] = negocioDeInventario('costo-vigente');
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => '5',
            'costo_unitario' => '22',
        ]],
    ], $supervisor);
    $detalle = $orden->detalles->sole();
    $recepcion = app(RecibirMercaderia::class);
    $correcciones = app(CorregirCostoCompra::class);

    $recepcion->recibir($orden, [['detalle_id' => $detalle->id, 'cantidad' => '2']], $supervisor, true);
    $correcciones->corregir($detalle, '23', 'Primera corrección.', $supervisor);
    $recepcion->recibir($orden, [['detalle_id' => $detalle->id, 'cantidad' => '3']], $supervisor, true);

    expect($variante->refresh()->precio_costo)->toBe('23.0000');
    expect($correcciones->vistaPrevia($detalle->fresh(), '24'))->toBe('24.0000');
    $segunda = $correcciones->corregir($detalle, '24', 'Segunda corrección.', $supervisor);

    expect($segunda->costo_anterior)->toBe('23.0000')
        ->and($segunda->promedio_nuevo)->toBe('24.0000')
        ->and($variante->refresh()->precio_costo)->toBe('24.0000')
        ->and($variante->precio_venta)->toBe('100.00')
        ->and($detalle->refresh()->costo_unitario)->toBe('22.0000')
        ->and($variante->stocks()->where('almacen_id', $almacen->id)->sole()->cantidad_actual)->toBe('5.0000');
});

function negocioDeInventario(string $dominio): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('administrador');
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('vendedor');
    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $cajero->sucursales()->attach($sucursal->id, ['tenant_id' => $tenant->id]);
    $almacen = Almacen::factory()->create(['nombre' => 'Estante']);
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $variante = ProductoVariante::factory()->create([
        'sku' => '6204-2RS',
        'precio_venta' => '100.00',
        'precio_mayor' => '80.00',
        'precio_costo' => '40.0000',
    ]);
    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('cajero', 'supervisor', 'sucursal', 'almacen', 'variante', 'ocasional');
}

function cargarPiezas(ProductoVariante $variante, Almacen $almacen, TenantUser $usuario, Proveedor $ocasional, string $cantidad): void
{
    recibir($variante, $almacen, $usuario, $ocasional, $cantidad, '40');
}

function recibir(ProductoVariante $variante, $almacen, $usuario, $ocasional, string $cantidad, string $costo)
{
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => $cantidad,
            'costo_unitario' => $costo,
        ]],
    ], $usuario);

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => $cantidad],
    ], $usuario, true);

    return $orden->detalles->first()->refresh();
}
