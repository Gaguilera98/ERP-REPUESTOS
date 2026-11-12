<?php

use App\Catalogo\VariantesProducto;
use App\Enums\MagnitudUnidad;
use App\Enums\TipoAtributoCategoria;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\Categorias\Pages\ManageCategorias;
use App\Filament\Negocio\Resources\Categorias\Schemas\CategoriaForm;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\CreateProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\EditProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\KardexProductoMaestro;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ListProductoMaestros;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\InventarioStock;
use App\Models\Marca;
use App\Models\MovimientoInventario;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Database\Seeders\CatalogoSeeder;
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

it('muestra el stock total del producto y separa las reservas por almacén y SKU en su ficha', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');
    $deposito = Almacen::factory()->create(['nombre' => 'Depósito Central']);
    $tienda = Almacen::factory()->create(['nombre' => 'Tienda Norte']);
    $producto = ProductoMaestro::factory()->create();
    $variante = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => 'STOCK-A']);
    $otraVariante = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => 'STOCK-B']);
    $otroProducto = ProductoVariante::factory()->create();
    $variante->stocks()->where('almacen_id', $deposito->id)->sole()
        ->forceFill(['cantidad_actual' => '7.2500', 'cantidad_reservada' => '2.0000'])->save();
    $otraVariante->stocks()->where('almacen_id', $tienda->id)->sole()
        ->forceFill(['cantidad_actual' => '4.5000'])->save();
    $otroProducto->stocks()->where('almacen_id', $deposito->id)->sole()
        ->forceFill(['cantidad_actual' => '30.0000'])->save();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')->test(ListProductoMaestros::class)
        ->assertTableColumnFormattedStateSet('stock_fisico', '11.75 pza', $producto)
        ->assertSee('9.75 disponibles');

    Livewire::actingAs($supervisor, 'tenant')->test(EditProductoMaestro::class, ['record' => $producto->getKey()])
        ->assertSeeInOrder(['Stock por almacén', 'Depósito Central', 'STOCK-A', '7.25 pza', '2 pza', '5.25 pza'])
        ->assertSeeInOrder(['Tienda Norte', 'STOCK-B', '4.5 pza'])
        ->assertDontSee('30 pza');

    tenancy()->end();
});

it('consulta el kardex del producto y filtra por almacén, tipo y día', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');
    $producto = ProductoMaestro::factory()->create();
    $variante = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id]);
    $deposito = Almacen::factory()->create();
    $tienda = Almacen::factory()->create();
    $movimiento = fn (array $datos): MovimientoInventario => MovimientoInventario::factory()->create([
        'producto_variante_id' => $variante->id, 'almacen_id' => $deposito->id, 'user_id' => $cajero->id,
        'created_at' => '2026-11-11 10:00:00', ...$datos,
    ]);
    $compra = $movimiento([]);
    $express = $movimiento(['tipo_movimiento' => TipoMovimientoInventario::EntradaFlashEmergencia]);
    $salida = $movimiento(['tipo_movimiento' => TipoMovimientoInventario::SalidaVenta, 'stock_previo' => 2, 'stock_posterior' => 1]);
    $otroDia = $movimiento(['created_at' => '2026-11-10 10:00:00']);
    $otroAlmacen = $movimiento(['almacen_id' => $tienda->id]);
    $otroProducto = $movimiento(['producto_variante_id' => ProductoVariante::factory()->create()->id]);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    Livewire::actingAs($cajero, 'tenant')->test(KardexProductoMaestro::class, ['record' => $producto->id])
        ->assertCanSeeTableRecords([$compra, $express, $salida, $otroDia, $otroAlmacen])
        ->assertCanNotSeeTableRecords([$otroProducto])
        ->filterTable('almacen_id', $deposito)
        ->filterTable('tipo_movimiento', [TipoMovimientoInventario::EntradaCompra, TipoMovimientoInventario::EntradaFlashEmergencia])
        ->filterTable('dia', ['fecha' => '2026-11-11'])
        ->assertCanSeeTableRecords([$compra, $express])
        ->assertCanNotSeeTableRecords([$salida, $otroDia, $otroAlmacen, $otroProducto]);

    $usuarioSinAcceso = TenantUser::factory()->create();
    Livewire::actingAs($usuarioSinAcceso, 'tenant')->test(KardexProductoMaestro::class, ['record' => $producto->id])->assertForbidden();

    $otroTenant = Tenant::factory()->create();
    tenancy()->initialize($otroTenant);
    $otroCajero = TenantUser::factory()->create();
    $otroCajero->assignRole('cajero');
    Livewire::actingAs($otroCajero, 'tenant')->test(KardexProductoMaestro::class, ['record' => $producto->id])->assertNotFound();

    tenancy()->end();
});

it('no tiene columnas fijas de dimensiones: los atributos viven en atributos_json', function () {
    expect(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'diametro_interno_mm'))->toBeFalse()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'diametro_externo_mm'))->toBeFalse()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'ancho_mm'))->toBeFalse()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'atributos_json'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('categorias', 'plantilla_atributos'))->toBeFalse()
        ->and(Schema::connection('pool_shared_1')->hasTable('unidades_medida'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasTable('categoria_atributos'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasTable('marcas'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_maestros', 'marca_id'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'imagen'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_maestros', 'imagenes'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('inventario_stocks', 'ubicacion'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('categorias', 'is_active'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('marcas', 'is_active'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_maestros', 'is_active'))->toBeTrue()
        ->and(Schema::connection('pool_shared_1')->hasColumn('productos_variantes', 'is_active'))->toBeTrue();
});

it('trata Toyota y toyota como la misma marca y la asigna al producto', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);

    $toyota = Marca::factory()->create(['nombre' => ' Toyota ']);
    $producto = ProductoMaestro::factory()->create(['marca_id' => $toyota->id]);

    expect($toyota->fresh()->nombre)->toBe('Toyota')
        ->and($producto->fresh()->marca->is($toyota))->toBeTrue()
        ->and(fn () => Marca::factory()->create(['nombre' => 'toyota']))->toThrow(QueryException::class)
        ->and(fn () => $toyota->delete())->toThrow(QueryException::class);

    tenancy()->initialize($sur);

    expect(Marca::factory()->create(['nombre' => 'Toyota'])->nombre)->toBe('Toyota');

    tenancy()->end();
});

it('mantiene el sku único por negocio y permite código de barras vacío repetido', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    ProductoVariante::factory()->create(['sku' => '6204-2RS', 'codigo_barras' => null]);
    ProductoVariante::factory()->create(['sku' => '6205-2RS', 'codigo_barras' => null]);

    expect(fn () => ProductoVariante::factory()->create(['sku' => '6204-2RS']))
        ->toThrow(QueryException::class);

    tenancy()->initialize($sur);
    $otra = ProductoVariante::factory()->create(['sku' => '6204-2RS', 'codigo_barras' => '7790001']);

    expect($otra->precio_venta_dolar)->toBeNull()
        ->and($otra->precio_mayor_dolar)->toBeNull();

    expect(fn () => ProductoVariante::factory()->create(['sku' => 'OTRA', 'codigo_barras' => '7790001']))
        ->toThrow(QueryException::class);

    tenancy()->end();
});

it('inicia cada variante en stock cero en cada almacén', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $deposito = Almacen::factory()->create(['nombre' => 'Depósito']);
    $variante = ProductoVariante::factory()->create(['sku' => '6204-2RS']);

    $stock = InventarioStock::query()->where('producto_variante_id', $variante->id)->get();

    expect($stock)->toHaveCount(1)
        ->and($stock->first()->almacen_id)->toBe($deposito->id)
        ->and($stock->first()->cantidad_actual)->toBe('0.0000')
        ->and($stock->first()->cantidad_reservada)->toBe('0.0000');

    $fila = $stock->first();
    $fila->update([
        'cantidad_actual' => 8,
        'cantidad_reservada' => 3,
    ]);

    expect($fila->fresh()->cantidad_actual)->toBe('0.0000')
        ->and($fila->fresh()->cantidad_reservada)->toBe('0.0000');

    expect(fn () => DB::connection('pool_shared_1')->table('inventario_stocks')->where('id', $fila->id)->update([
        'cantidad_actual' => -1,
    ]))->toThrow(QueryException::class);

    tenancy()->end();
});

it('agrega stock en cero cuando se crea un almacén después de la variante', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $variante = ProductoVariante::factory()->create();
    $almacen = Almacen::factory()->create(['nombre' => 'Nuevo']);

    $stock = InventarioStock::query()
        ->where('producto_variante_id', $variante->id)
        ->where('almacen_id', $almacen->id)
        ->first();

    expect($stock)->not->toBeNull()
        ->and($stock->cantidad_actual)->toBe('0.0000');

    tenancy()->end();
});

it('guarda los atributos de la categoría como número o texto, sin claves de más', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    /** @var Categoria $categoria */
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    $milimetro = UnidadMedida::factory()->create([
        'nombre' => 'Milímetro',
        'simbolo' => 'mm',
        'magnitud' => MagnitudUnidad::Longitud,
    ]);

    CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'diametro_interno',
        'etiqueta' => 'Diámetro interno',
        'obligatorio' => true,
        'orden' => 1,
        'unidad_medida_id' => $milimetro->id,
    ]);
    CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'diametro_externo',
        'etiqueta' => 'Diámetro externo',
        'obligatorio' => true,
        'orden' => 2,
        'unidad_medida_id' => $milimetro->id,
    ]);
    CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'ancho',
        'etiqueta' => 'Ancho',
        'obligatorio' => true,
        'orden' => 3,
        'unidad_medida_id' => $milimetro->id,
    ]);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ', 'ABIERTO'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
        'orden' => 4,
    ]);

    Almacen::factory()->create(['nombre' => 'Depósito']);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->fillForm([
            'nombre' => 'Rodamiento rígido',
            'categoria_id' => $categoria->id,
            'sku' => '6204-2RS',
            'precio_venta' => '25.50',
            'ficha' => [
                'diametro_interno' => '20',
                'diametro_externo' => '47',
                'ancho' => '14',
                'sello' => ['2RS'],
                'extra' => 'no va',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variante = ProductoVariante::query()->where('sku', '6204-2RS')->first();

    expect($variante)->not->toBeNull()
        ->and($variante->precio_venta)->toBe('25.50')
        ->and($variante->precio_costo)->toBe('0.0000')
        ->and($variante->atributos_json)->toBe([
            'diametro_interno' => 20,
            'diametro_externo' => 47,
            'ancho' => 14,
            'sello' => ['2RS'],
        ])
        ->and($variante->stocks)->toHaveCount(1)
        ->and($variante->stocks->first()->cantidad_actual)->toBe('0.0000');

    tenancy()->end();
});

it('guarda varios sellos en el producto y los vuelve a mostrar al editar', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    /** @var Categoria $categoria */
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ', 'ABIERTO'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->fillForm([
            'nombre' => 'Rodamiento 6204',
            'categoria_id' => $categoria->id,
            'sku' => '6204',
            'precio_venta' => '10',
            'ficha' => [
                'sello' => ['ZZ', '2RS'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variante = ProductoVariante::query()->where('sku', '6204')->first();
    $producto = $variante?->productoMaestro;

    expect($variante?->atributos_json)->toBe([
        'sello' => ['2RS', 'ZZ'],
    ]);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditProductoMaestro::class, ['record' => $producto->getKey()])
        ->assertFormSet(function (array $state): bool {
            expect($state['ficha']['sello'])->toBe(['2RS', 'ZZ']);

            return true;
        });

    $anterior = ProductoMaestro::factory()->create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Rodamiento viejo',
    ]);
    ProductoVariante::factory()->create([
        'producto_maestro_id' => $anterior->id,
        'sku' => '6204-ZZ',
        'atributos_json' => ['sello' => 'ZZ'],
    ]);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditProductoMaestro::class, ['record' => $anterior->getKey()])
        ->assertFormSet(function (array $state): bool {
            expect($state['ficha']['sello'])->toBe(['ZZ']);

            return true;
        });

    expect(fn () => ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => 'MALO-SELLO',
        'atributos_json' => ['sello' => ['INVENTADO']],
    ]))->toThrow(ValidationException::class);

    tenancy()->end();
});

it('rechaza un texto en un decimal y una unidad en un atributo que no se mide', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    $milimetro = UnidadMedida::factory()->create([
        'nombre' => 'Milímetro',
        'simbolo' => 'mm',
        'magnitud' => MagnitudUnidad::Longitud,
    ]);
    $voltio = UnidadMedida::factory()->create([
        'nombre' => 'Voltio',
        'simbolo' => 'V',
        'magnitud' => MagnitudUnidad::Electrico,
    ]);

    $diametro = CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'diametro_interno',
        'etiqueta' => 'Diámetro interno',
        'obligatorio' => true,
        'unidad_medida_id' => $milimetro->id,
    ]);

    $maestro = ProductoMaestro::factory()->create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Rodamiento',
    ]);

    expect(fn () => ProductoVariante::factory()->create([
        'producto_maestro_id' => $maestro->id,
        'sku' => 'MALO',
        'atributos_json' => ['diametro_interno' => 'veinte'],
    ]))->toThrow(ValidationException::class);

    $negativo = null;

    try {
        ProductoVariante::factory()->create([
            'producto_maestro_id' => $maestro->id,
            'sku' => 'NEGATIVO',
            'atributos_json' => ['diametro_interno' => -23],
        ]);
    } catch (ValidationException $capturada) {
        $negativo = $capturada;
    }

    expect($negativo)->toBeInstanceOf(ValidationException::class)
        ->and($negativo->errors()['atributos_json.diametro_interno'][0])->toBe('Diámetro interno no puede ser negativo.')
        ->and(ProductoVariante::query()->where('sku', 'NEGATIVO')->exists())->toBeFalse();

    expect(fn () => DB::connection('pool_shared_1')->table('categoria_atributos')->insert([
        'tenant_id' => tenant()->getTenantKey(),
        'categoria_id' => $categoria->id,
        'clave' => 'rosca',
        'etiqueta' => 'Rosca',
        'tipo' => 'texto',
        'obligatorio' => false,
        'orden' => 1,
        'unidad_medida_id' => $milimetro->id,
        'opciones' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => $diametro->update(['unidad_medida_id' => $voltio->id]))
        ->toThrow(ValidationException::class);

    tenancy()->end();
});

it('el supervisor crea productos y el cajero no puede editar el catálogo', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    Almacen::factory()->create(['nombre' => 'Depósito']);
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    expect($cajero->can('viewAny', ProductoMaestro::class))->toBeTrue()
        ->and($cajero->can('create', ProductoMaestro::class))->toBeFalse()
        ->and($cajero->can('create', Categoria::class))->toBeFalse()
        ->and($supervisor->can('create', ProductoMaestro::class))->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->fillForm([
            'nombre' => 'Rodamiento rígido',
            'categoria_id' => $categoria->id,
            'sku' => '6204-2RS',
            'precio_venta' => '25.50',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $variante = ProductoVariante::query()->where('sku', '6204-2RS')->first();

    expect($variante)->not->toBeNull()
        ->and($variante->precio_venta)->toBe('25.50')
        ->and($variante->precio_costo)->toBe('0.0000')
        ->and($variante->stocks)->toHaveCount(1)
        ->and($variante->stocks->first()->cantidad_actual)->toBe('0.0000');

    tenancy()->end();

    $this->actingAs($cajero, 'tenant')
        ->get('http://norte.localhost/negocio/productos/create')
        ->assertForbidden();
});

it('crea la marca desde el producto y rechaza el mismo nombre con otra mayúscula', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->callFormComponentAction('marca_id', 'createOption', data: [
            'nombre' => ' SKF ',
        ])
        ->assertHasNoFormComponentActionErrors();

    $marca = Marca::query()->where('nombre', 'SKF')->first();

    expect($marca)->not->toBeNull()
        ->and($pagina->get('data.marca_id'))->toEqual($marca->id);

    $pagina
        ->callFormComponentAction('marca_id', 'createOption', data: [
            'nombre' => 'skf',
        ])
        ->assertHasFormComponentActionErrors(['nombre']);

    expect(Marca::query()->count())->toBe(1);

    tenancy()->end();
});

it('agrega un sello que no estaba en la lista y rechaza el mismo con otra mayúscula', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    $sello = CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->fillForm([
            'categoria_id' => $categoria->id,
            'con_variantes' => true,
            'opciones' => [
                ['clave' => 'sello', 'valores' => ['2RS']],
            ],
        ])
        ->callFormComponentAction('opciones.0.valores', 'agregarValor', data: [
            'valor' => ' RS ',
        ])
        ->assertHasNoFormComponentActionErrors();

    $sello->refresh();

    expect($sello->opciones)->toBe(['2RS', 'ZZ', 'RS'])
        ->and($pagina->get('data.opciones.0.valores'))->toContain('RS');

    $pagina
        ->callFormComponentAction('opciones.0.valores', 'agregarValor', data: [
            'valor' => 'rs',
        ])
        ->assertHasFormComponentActionErrors(['valor']);

    expect($sello->fresh()->opciones)->toBe(['2RS', 'ZZ', 'RS']);

    tenancy()->end();
});

it('arma las combinaciones de color y talla y conserva el código de cada una', function () {
    $variantes = app(VariantesProducto::class);

    $filas = $variantes->combinar(
        [
            ['clave' => 'color', 'valores' => ['rojo', 'verde']],
            ['clave' => 'talla', 'valores' => ['S', 'M']],
        ],
        [
            [
                'id' => 8,
                'ejes_json' => '{"color":"rojo","talla":"S"}',
                'sku' => 'POL-R-S',
                'precio_venta' => '40',
            ],
        ],
        'POL-ORIGINAL',
        '10',
    );

    expect($filas)->toHaveCount(4)
        ->and($variantes->etiqueta($filas[0]))->toBe('rojo · S')
        ->and($filas[0]['id'])->toBe(8)
        ->and($filas[0]['sku'])->toBe('POL-R-S')
        ->and($variantes->etiqueta($filas[1]))->toBe('rojo · M')
        ->and($filas[1]['sku'])->toBeNull()
        ->and($filas[1]['precio_venta'])->toBe('10')
        ->and($variantes->etiqueta($filas[3]))->toBe('verde · M');
});

it('crea una variante por cada combinación y deja la ficha común en todas', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $categoria = Categoria::factory()->create(['nombre' => 'Poleras']);
    CategoriaAtributo::factory()->lista(['rojo', 'verde'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'color',
        'etiqueta' => 'Color',
        'obligatorio' => true,
        'orden' => 1,
    ]);
    CategoriaAtributo::factory()->lista(['S', 'M'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'talla',
        'etiqueta' => 'Talla',
        'obligatorio' => true,
        'orden' => 2,
    ]);
    CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'peso',
        'etiqueta' => 'Peso',
        'obligatorio' => false,
        'orden' => 3,
    ]);
    Almacen::factory()->create(['nombre' => 'Depósito']);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class)
        ->fillForm([
            'nombre' => 'Polera de hombre',
            'categoria_id' => $categoria->id,
            'con_variantes' => true,
            'ficha' => ['peso' => '200'],
            'opciones' => [
                ['clave' => 'color', 'valores' => ['rojo', 'verde']],
                ['clave' => 'talla', 'valores' => ['S', 'M']],
            ],
            'variantes' => [
                ['ejes_json' => '{"color":"rojo","talla":"S"}', 'sku' => 'POL-R-S', 'precio_venta' => '40'],
                ['ejes_json' => '{"color":"rojo","talla":"M"}', 'sku' => 'POL-R-M', 'precio_venta' => '40'],
                ['ejes_json' => '{"color":"verde","talla":"S"}', 'sku' => 'POL-V-S', 'precio_venta' => '42'],
                ['ejes_json' => '{"color":"verde","talla":"M"}', 'sku' => 'POL-V-M', 'precio_venta' => '42'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $roja = ProductoVariante::query()->where('sku', 'POL-R-S')->first();

    expect(ProductoVariante::query()->count())->toBe(4)
        ->and($roja)->not->toBeNull()
        ->and($roja->atributos_json)->toBe([
            'color' => 'rojo',
            'talla' => 'S',
            'peso' => 200,
        ])
        ->and($roja->stocks)->toHaveCount(1)
        ->and($roja->productoMaestro->variantes)->toHaveCount(4);

    tenancy()->end();
});

it('apaga el producto y una variante, y deja fuera del alta el rubro y la marca inactivos', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $viva = Categoria::factory()->create(['nombre' => 'Rodamientos vivos']);
    $apagada = Categoria::factory()->create(['nombre' => 'Frenos apagados', 'is_active' => false]);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create([
        'categoria_id' => $viva->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    Marca::factory()->create(['nombre' => 'SKF viva']);
    Marca::factory()->create(['nombre' => 'NSK apagada', 'is_active' => false]);
    Almacen::factory()->create(['nombre' => 'Depósito']);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $crear = Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateProductoMaestro::class);

    expect($crear->html())
        ->toContain('Rodamientos vivos')
        ->toContain('SKF viva')
        ->not->toContain('Frenos apagados')
        ->not->toContain('NSK apagada');

    $crear
        ->fillForm([
            'nombre' => 'Rodamiento 6204',
            'categoria_id' => $viva->id,
            'is_active' => false,
            'con_variantes' => true,
            'opciones' => [
                ['clave' => 'sello', 'valores' => ['2RS', 'ZZ']],
            ],
            'variantes' => [
                ['ejes_json' => '{"sello":"2RS"}', 'sku' => '6204-2RS', 'precio_venta' => '300', 'is_active' => true],
                ['ejes_json' => '{"sello":"ZZ"}', 'sku' => '6204-ZZ', 'precio_venta' => '280', 'is_active' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $producto = ProductoMaestro::query()->where('nombre', 'Rodamiento 6204')->first();

    expect($producto)->not->toBeNull()
        ->and($producto->is_active)->toBeFalse()
        ->and(ProductoVariante::query()->where('sku', '6204-2RS')->first()?->is_active)->toBeTrue()
        ->and(ProductoVariante::query()->where('sku', '6204-ZZ')->first()?->is_active)->toBeFalse();

    $producto->update(['categoria_id' => $apagada->id]);

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditProductoMaestro::class, ['record' => $producto->getKey()])
        ->assertSee('Frenos apagados');

    tenancy()->end();
});

it('suma la foto de la variante a la galería del producto', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $producto = ProductoMaestro::factory()->create([
        'imagenes' => ['galeria.jpg'],
    ]);

    app(VariantesProducto::class)->guardar($producto, [
        'con_variantes' => true,
        'ficha' => [],
        'variantes' => [
            ['ejes_json' => '{}', 'sku' => 'A-1', 'precio_venta' => 10, 'imagen' => 'galeria.jpg'],
            ['ejes_json' => '{}', 'sku' => 'A-2', 'precio_venta' => 12, 'imagen' => 'rojo.jpg'],
        ],
    ]);

    expect($producto->fresh()->imagenes)->toBe(['galeria.jpg', 'rojo.jpg'])
        ->and($producto->variantes()->count())->toBe(2);

    tenancy()->end();
});

it('abre un producto con dos sellos como variantes y no las duplica al guardar', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create([
        'categoria_id' => $categoria->id,
        'clave' => 'sello',
        'etiqueta' => 'Sello',
    ]);
    CategoriaAtributo::factory()->decimal()->create([
        'categoria_id' => $categoria->id,
        'clave' => 'diametro_interno',
        'etiqueta' => 'Diámetro interno',
        'obligatorio' => true,
        'orden' => 1,
    ]);
    $producto = ProductoMaestro::factory()->create([
        'categoria_id' => $categoria->id,
        'nombre' => 'Rodamiento 6204',
    ]);
    ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6204-2RS',
        'precio_venta' => 10,
        'atributos_json' => ['sello' => '2RS', 'diametro_interno' => 20],
    ]);
    ProductoVariante::factory()->create([
        'producto_maestro_id' => $producto->id,
        'sku' => '6204-ZZ',
        'precio_venta' => 12,
        'atributos_json' => ['sello' => 'ZZ', 'diametro_interno' => 20],
    ]);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(EditProductoMaestro::class, ['record' => $producto->getKey()])
        ->assertFormSet(function (array $state): bool {
            expect($state['con_variantes'])->toBeTrue()
                ->and($state['ficha']['diametro_interno'])->toEqual(20)
                ->and($state['opciones'])->toHaveCount(1)
                ->and($state['opciones'][0]['clave'] ?? collect($state['opciones'])->first()['clave'])->toBe('sello')
                ->and($state['variantes'])->toHaveCount(2);

            return true;
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ProductoVariante::query()->count())->toBe(2)
        ->and(ProductoVariante::query()->where('sku', '6204-2RS')->exists())->toBeTrue()
        ->and(ProductoVariante::query()->where('sku', '6204-ZZ')->exists())->toBeTrue();

    tenancy()->end();
});

it('lista primero lo último registrado y permite invertir el orden', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    $vieja = Categoria::factory()->create(['nombre' => 'Frenos viejos']);
    $nueva = Categoria::factory()->create(['nombre' => 'Rodamientos nuevos']);
    $vieja->forceFill(['created_at' => now()->subDay()])->save();
    $nueva->forceFill(['created_at' => now()])->save();

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ManageCategorias::class)
        ->assertCanSeeTableRecords([$nueva, $vieja], inOrder: true)
        ->filterTable('orden', 'asc')
        ->assertCanSeeTableRecords([$vieja, $nueva], inOrder: true);

    $momento = now();
    $primera = Categoria::factory()->create(['nombre' => 'Primera']);
    $segunda = Categoria::factory()->create(['nombre' => 'Segunda']);
    $primera->forceFill(['created_at' => $momento])->save();
    $segunda->forceFill(['created_at' => $momento])->save();

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ManageCategorias::class)
        ->assertCanSeeTableRecords([$segunda, $primera, $nueva, $vieja], inOrder: true)
        ->filterTable('orden', 'asc')
        ->assertCanSeeTableRecords([$vieja, $nueva, $primera, $segunda], inOrder: true);

    tenancy()->end();
});

it('lista todos los atributos del rubro, también el cuarto', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);

    (new CatalogoSeeder)->run();

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ManageCategorias::class)
        ->assertSee('Diámetro interno')
        ->assertSee('Diámetro externo')
        ->assertSee('Ancho')
        ->assertSee('Sello');

    tenancy()->end();
});

it('muestra el nombre de la unidad y no un objeto', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $milimetro = UnidadMedida::factory()->create([
        'nombre' => 'Milímetro',
        'simbolo' => 'mm',
        'magnitud' => MagnitudUnidad::Longitud,
    ]);

    $opciones = CategoriaForm::opcionesDeUnidad();
    $longitud = $opciones['Longitud'];

    expect($longitud)->toBeArray()
        ->and($longitud[$milimetro->id])->toBe('Milímetro (mm)')
        ->and(collect($longitud)->every(fn (mixed $etiqueta): bool => is_string($etiqueta)))->toBeTrue();

    tenancy()->end();
});

it('siembra unidades y la categoría de rodamientos una sola vez por negocio', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();

    tenancy()->initialize($norte);
    (new CatalogoSeeder)->run();
    (new CatalogoSeeder)->run();

    $rodamientos = Categoria::query()->where('nombre', 'Rodamientos')->first();
    $interno = $rodamientos?->atributos()->where('clave', 'diametro_interno')->first();

    expect(UnidadMedida::query()->count())->toBe(12)
        ->and(Categoria::query()->count())->toBe(1)
        ->and($rodamientos)->not->toBeNull()
        ->and($rodamientos->atributos)->toHaveCount(4)
        ->and($rodamientos->atributos()->where('clave', 'marca')->exists())->toBeFalse()
        ->and($interno?->tipo)->toBe(TipoAtributoCategoria::Decimal)
        ->and($interno?->obligatorio)->toBeTrue()
        ->and($interno?->unidadMedida?->simbolo)->toBe('mm')
        ->and($rodamientos->atributos()->where('clave', 'sello')->first()?->opciones)->toBe(['2RS', 'ZZ', 'ABIERTO']);

    tenancy()->initialize($sur);

    expect(UnidadMedida::query()->count())->toBe(7)
        ->and(Categoria::query()->count())->toBe(0);

    tenancy()->end();
});
