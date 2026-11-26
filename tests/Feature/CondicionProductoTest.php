<?php

use App\Caja\AbrirCajaSesion;
use App\Catalogo\AsegurarCondicionesProducto;
use App\Catalogo\VariantesProducto;
use App\Enums\TipoAtributoCategoria;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\CreateProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\EditProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ListProductoMaestros;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\CondicionProducto;
use App\Models\MovimientoInventario;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Tenancy\AccesoSucursales;
use App\Ventas\BuscarProductosMostrador;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (config('database.connections.pool_shared_1.driver') !== 'sqlite' || config('database.connections.pool_shared_1.database') !== ':memory:') {
        throw new LogicException('Estas pruebas solo pueden preparar el pool SQLite en memoria.');
    }
    Schema::connection('pool_shared_1')->dropAllTables();
    $this->artisan('migrate', ['--database' => 'pool_shared_1', '--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
});

afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

it('crea las condiciones iniciales al crear el tenant y admite otras sin cambiar el esquema', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    expect(CondicionProducto::query()->orderBy('orden')->pluck('nombre', 'codigo')->all())->toBe(['nuevo' => 'Nuevo', 'usado' => 'Usado']);
    $condicion = CondicionProducto::factory()->create(['codigo' => 'reacondicionado', 'nombre' => 'Reacondicionado']);
    $producto = ProductoMaestro::factory()->create(['codigo_repuesto' => '6206']);
    $variante = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'condicion_producto_id' => $condicion->id]);

    expect($variante->condicion->is($condicion))->toBeTrue();
    expect($condicion->variantes->sole()->is($variante))->toBeTrue();
    expect($variante->productoMaestro->codigo_repuesto)->toBe('6206');
});

it('no clasifica productos ni altera stock al asegurar las condiciones iniciales', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    Almacen::factory()->create();
    $variante = ProductoVariante::factory()->create(['sku' => '6206', 'condicion_producto_id' => null]);
    $stock = $variante->stocks()->sole();
    $stock->cantidad_actual = '7.0000';
    $stock->cantidad_reservada = '2.0000';
    $stock->save();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    $usado->update(['nombre' => 'Usado revisado', 'is_active' => false]);

    app(AsegurarCondicionesProducto::class)->handle();

    expect(CondicionProducto::query()->count())->toBe(2);
    expect($usado->refresh()->nombre)->toBe('Usado revisado');
    expect($usado->is_active)->toBeFalse();
    expect($variante->refresh()->condicion_producto_id)->toBeNull();
    expect($variante->sku)->toBe('6206');
    expect($stock->refresh()->cantidad_actual)->toBe('7.0000');
    expect($stock->cantidad_reservada)->toBe('2.0000');
});

it('no permite asignar la condición de otro tenant aunque se conozca su id', function () {
    $primero = Tenant::factory()->create();
    tenancy()->initialize($primero);
    $variante = ProductoVariante::factory()->create();
    tenancy()->end();
    $segundo = Tenant::factory()->create();
    tenancy()->initialize($segundo);
    $ajena = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    tenancy()->end();
    tenancy()->initialize($primero);

    expect(CondicionProducto::query()->find($ajena->id))->toBeNull();
    expect(fn () => $variante->update(['condicion_producto_id' => $ajena->id]))->toThrow(QueryException::class);
});

it('conserva las variantes si se intenta eliminar una condición utilizada', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $condicion = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $variante = ProductoVariante::factory()->create(['condicion_producto_id' => $condicion->id]);

    expect(fn () => $condicion->delete())->toThrow(QueryException::class);
    expect($variante->fresh())->not->toBeNull();
    expect($condicion->fresh())->not->toBeNull();
});

it('crea y edita la condición desde la ficha del producto sin cambiar su stock', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create();
    Almacen::factory()->create();
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($usuario, 'tenant')->test(CreateProductoMaestro::class)
        ->assertFormSet(['condicion_producto_id' => $nuevo->id])
        ->fillForm(['nombre' => 'Rodamiento de prueba', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '6206', 'sku' => '6206-U', 'precio_venta' => '25', 'condicion_producto_id' => $usado->id])
        ->call('create')->assertHasNoFormErrors();
    $variante = ProductoVariante::query()->where('sku', '6206-U')->sole();
    expect($variante->condicion_producto_id)->toBe($usado->id);
    expect($variante->productoMaestro->codigo_repuesto)->toBe('6206');

    Livewire::actingAs($usuario, 'tenant')->test(EditProductoMaestro::class, ['record' => $variante->producto_maestro_id])
        ->assertFormSet(['condicion_producto_id' => $usado->id, 'codigo_repuesto' => '6206'])
        ->assertSee('Usado')
        ->fillForm(['condicion_producto_id' => $nuevo->id])
        ->call('save')->assertHasNoFormErrors();

    expect($variante->refresh()->condicion_producto_id)->toBe($nuevo->id);
    expect($variante->stocks()->sole()->cantidad_actual)->toBe('0.0000');
    Livewire::actingAs($usuario, 'tenant')->test(ListProductoMaestros::class)
        ->searchTable('6206')->assertCanSeeTableRecords([$variante->productoMaestro])
        ->assertTableColumnFormattedStateSet('codigo_repuesto', '6206', $variante->productoMaestro);
});

it('rechaza condiciones vacías inactivas o de otro negocio al crear productos', function (string $caso) {
    $otro = Tenant::factory()->create();
    tenancy()->initialize($otro);
    $ajena = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    tenancy()->end();
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create();
    $inactiva = CondicionProducto::factory()->create(['is_active' => false]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $valor = match ($caso) {
        'vacia' => null, 'inactiva' => $inactiva->id, 'ajena' => $ajena->id
    };

    Livewire::actingAs($usuario, 'tenant')->test(CreateProductoMaestro::class)
        ->fillForm(['nombre' => 'No guardar', 'categoria_id' => $categoria->id, 'sku' => 'INVALIDO', 'precio_venta' => '25', 'condicion_producto_id' => $valor])
        ->call('create')->assertHasFormErrors(['condicion_producto_id'])
        ->assertSee($caso === 'vacia' ? 'Elegí la condición del producto.' : 'Elegí una condición disponible de este negocio.');
    expect(ProductoVariante::query()->where('sku', 'INVALIDO')->exists())->toBeFalse();
})->with(['vacia', 'inactiva', 'ajena']);

it('mantiene las condiciones de cada variante al editar y regenerar sus combinaciones', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create();
    CategoriaAtributo::factory()->create(['categoria_id' => $categoria->id, 'clave' => 'sello', 'tipo' => TipoAtributoCategoria::Lista, 'opciones' => ['ZZ', '2RS']]);
    $producto = ProductoMaestro::factory()->create(['categoria_id' => $categoria->id]);
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    $primera = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'atributos_json' => ['sello' => 'ZZ'], 'condicion_producto_id' => $nuevo->id]);
    $segunda = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'atributos_json' => ['sello' => '2RS'], 'condicion_producto_id' => $usado->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $estado = app(VariantesProducto::class)->estadoDesde($producto);
    $estado['variantes'][0]['condicion_producto_id'] = $usado->id;
    $estado['variantes'][1]['condicion_producto_id'] = $nuevo->id;

    Livewire::actingAs($usuario, 'tenant')->test(EditProductoMaestro::class, ['record' => $producto->id])
        ->fillForm(['variantes' => $estado['variantes']])
        ->call('save')->assertHasNoFormErrors();
    expect($primera->refresh()->condicion_producto_id)->toBe($usado->id);
    expect($segunda->refresh()->condicion_producto_id)->toBe($nuevo->id);
    $estado = app(VariantesProducto::class)->estadoDesde($producto->fresh());
    $filas = app(VariantesProducto::class)->combinar($estado['opciones'], $estado['variantes']);
    expect(array_column($filas, 'condicion_producto_id'))->toBe([$usado->id, $nuevo->id]);
});

it('conserva una condición desactivada ya asignada al editar otros datos del producto', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $condicion = CondicionProducto::factory()->create(['is_active' => false]);
    $variante = ProductoVariante::factory()->create(['condicion_producto_id' => $condicion->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($usuario, 'tenant')->test(EditProductoMaestro::class, ['record' => $variante->producto_maestro_id])
        ->fillForm(['nombre' => 'Nombre corregido'])->call('save')->assertHasNoFormErrors();
    expect($variante->refresh()->condicion_producto_id)->toBe($condicion->id);
});

it('genera nuevo y usado sin atributos de categoría y permite guardarlos y volver a editarlos', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create();
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($usuario, 'tenant')->test(CreateProductoMaestro::class)
        ->fillForm(['nombre' => 'Rodamiento 45670', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '45670', 'precio_venta' => '100'])
        ->set('data.con_variantes', true)
        ->set('data.condiciones_variantes', [$nuevo->id, $usado->id]);
    $filas = array_values($pagina->get('data.variantes'));
    expect(array_column($filas, 'sku'))->toBe(['45670-NUEVO', '45670-USADO']);
    $filas[1]['precio_venta'] = '60';
    $pagina->fillForm(['variantes' => $filas])->call('create')->assertHasNoFormErrors();
    $producto = ProductoMaestro::query()->where('codigo_repuesto', '45670')->sole();
    expect($producto->variantes)->toHaveCount(2);
    expect($producto->variantes->pluck('atributos_json')->all())->toBe([[], []]);
    expect($producto->variantes->pluck('precio_venta')->all())->toBe(['100.00', '60.00']);

    Livewire::actingAs($usuario, 'tenant')->test(EditProductoMaestro::class, ['record' => $producto->id])
        ->assertFormSet(['con_variantes' => true, 'condiciones_variantes' => [$nuevo->id, $usado->id]])
        ->call('save')->assertHasNoFormErrors();
    expect($producto->variantes()->count())->toBe(2);
});

it('combina condición y sello sin intercambiar identidades precios ni stocks', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    $servicio = app(VariantesProducto::class);
    $opciones = [['clave' => 'sello', 'valores' => ['2RS', 'ZZ']]];
    $filas = $servicio->combinar($opciones, [], condicionSimple: $nuevo->id, condiciones: [$nuevo->id, $usado->id], codigoComun: '45670');
    expect(array_column($filas, 'sku'))->toBe(['45670-NUEVO-2RS', '45670-USADO-2RS', '45670-NUEVO-ZZ', '45670-USADO-ZZ']);
    foreach ($filas as $indice => &$fila) {
        $fila['id'] = $indice + 1;
        $fila['precio_venta'] = (string) (100 + $indice);
    }
    unset($fila);
    $regeneradas = $servicio->combinar($opciones, $filas, condiciones: [$usado->id, $nuevo->id]);
    expect(array_column($regeneradas, 'id'))->toBe([2, 1, 4, 3]);
    expect(array_column($regeneradas, 'precio_venta'))->toBe(['101', '100', '103', '102']);
});

it('conserva el SKU y el stock de la variante simple al agregar una condición y bloquea quitarla con stock', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    Almacen::factory()->create();
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->sole();
    $usado = CondicionProducto::query()->where('codigo', 'usado')->sole();
    $variante = ProductoVariante::factory()->create(['sku' => '45670', 'condicion_producto_id' => $nuevo->id]);
    $variante->productoMaestro->update(['codigo_repuesto' => '45670']);
    $variante->stocks()->sole()->forceFill(['cantidad_actual' => '8'])->save();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($usuario, 'tenant')->test(EditProductoMaestro::class, ['record' => $variante->producto_maestro_id])
        ->set('data.con_variantes', true)->set('data.condiciones_variantes', [$nuevo->id, $usado->id])
        ->call('save')->assertHasNoFormErrors();
    expect($variante->fresh()->sku)->toBe('45670');
    expect($variante->stocks()->sole()->cantidad_actual)->toBe('8.0000');
    $filas = array_values($pagina->get('data.variantes'));
    $filas = array_values(array_filter($filas, fn (array $fila): bool => (int) $fila['condicion_producto_id'] !== $nuevo->id));
    $otra = $filas[0];
    $otra['id'] = null;
    $otra['sku'] = 'OTRO-SKU';
    $otra['ejes_json'] = '{"otro":"valor"}';
    $pagina->fillForm(['variantes' => [$filas[0], $otra]])->call('save')->assertHasFormErrors(['variantes']);
    expect($variante->fresh())->not->toBeNull();
    expect($variante->stocks()->sole()->cantidad_actual)->toBe('8.0000');
});

it('busca el código común muestra ambas condiciones y vende solo el stock elegido', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $sucursal = Sucursal::factory()->create();
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    app(AccesoSucursales::class)->seleccionar($usuario, $sucursal->id);
    $producto = ProductoMaestro::factory()->create(['nombre' => 'Rodamiento 45670', 'codigo_repuesto' => '45670']);
    $nuevo = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => '45670', 'precio_venta' => '100', 'condicion_producto_id' => CondicionProducto::query()->where('codigo', 'nuevo')->value('id')]);
    $usado = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => '45670-U', 'precio_venta' => '60', 'condicion_producto_id' => CondicionProducto::query()->where('codigo', 'usado')->value('id')]);
    $nuevo->stocks()->sole()->forceFill(['cantidad_actual' => '8'])->save();
    $usado->stocks()->sole()->forceFill(['cantidad_actual' => '3'])->save();
    app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => '0'], $usuario);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    expect(app(BuscarProductosMostrador::class)->exacta('45670'))->toBeNull();
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(MostradorDelNegocio::class)
        ->set('busqueda', '45670')->assertCount('resultadosBusqueda', 2)->assertSee('Nuevo')->assertSee('Usado')
        ->call('buscarYAgregar')->assertCount('lineas', 0)->assertNotified('Elegí la variante')
        ->call('seleccionarResultado', $usado->id)->assertSet('lineas.0.condicion', 'Usado')->assertSet('lineas.0.precio_unitario', '60.00')
        ->assertSet('lineas.0.codigo_repuesto', '45670')->assertSee('SKU interno: 45670-U')
        ->set('pagos.0.monto', '60')->call('completar')->assertHasNoErrors();
    expect(Venta::query()->sole()->detalles()->sole()->producto_variante_id)->toBe($usado->id);
    expect($nuevo->stocks()->sole()->cantidad_actual)->toBe('8.0000');
    expect($usado->stocks()->sole()->cantidad_actual)->toBe('2.0000');
    expect(app(BuscarProductosMostrador::class)->exacta('45670-U')->id)->toBe($usado->id);
});

it('rechaza variantes con condición y opciones repetidas antes de guardar', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $producto = ProductoMaestro::factory()->create();
    $nuevo = CondicionProducto::query()->where('codigo', 'nuevo')->value('id');
    expect(fn () => app(VariantesProducto::class)->guardar($producto, [
        'con_variantes' => true,
        'variantes' => [
            ['sku' => 'PRIMERO', 'precio_venta' => '10', 'condicion_producto_id' => $nuevo],
            ['sku' => 'SEGUNDO', 'precio_venta' => '12', 'condicion_producto_id' => $nuevo],
        ],
    ]))->toThrow(ValidationException::class, 'misma condición');
    expect($producto->variantes()->exists())->toBeFalse();
});

it('no elimina una variante con movimientos aunque su stock sea cero', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $variante = ProductoVariante::factory()->create();
    MovimientoInventario::factory()->create(['producto_variante_id' => $variante->id]);
    expect(fn () => app(VariantesProducto::class)->guardar($variante->productoMaestro, [
        'sku' => 'REEMPLAZO', 'precio_venta' => '15',
    ]))->toThrow(ValidationException::class, 'movimientos');
    expect($variante->fresh())->not->toBeNull();
});

it('pide elegir al menos dos variantes cuando se activa la opción sin condiciones ni atributos', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    Livewire::actingAs($usuario, 'tenant')->test(CreateProductoMaestro::class)
        ->fillForm(['nombre' => 'Incompleto', 'categoria_id' => $categoria->id, 'con_variantes' => true])
        ->call('create')->assertHasFormErrors(['variantes']);
    expect(ProductoMaestro::query()->where('nombre', 'Incompleto')->exists())->toBeFalse();
});
