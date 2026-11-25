<?php

use App\Catalogo\AsegurarCondicionesProducto;
use App\Catalogo\VariantesProducto;
use App\Enums\TipoAtributoCategoria;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\CreateProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\EditProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ListProductoMaestros;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\CondicionProducto;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Tenant;
use App\Models\TenantUser;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        ->searchTable('6206')->assertCanSeeTableRecords([$variante->productoMaestro]);
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
