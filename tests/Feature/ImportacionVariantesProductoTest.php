<?php

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos;
use App\Catalogo\MapearColumnasProductos;
use App\Livewire\Negocio\RevisionImportacionProductos;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\CondicionProducto;
use App\Models\InventarioInicialDetalle;
use App\Models\MovimientoInventario;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Tenant;
use App\Models\TenantUser;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

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

function importacionConCondiciones(): array
{
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    $almacen = Almacen::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    return compact('tenant', 'usuario', 'categoria', 'almacen');
}

function filaConCondicion(array $cambios = []): array
{
    return array_replace(array_fill_keys(ArchivoProductos::COLUMNAS, ''), [
        'fila' => 2, 'codigo_producto' => '0045670', 'condicion' => 'Nuevo', 'sku' => '0045670-N',
        'nombre' => 'Rodamiento 45670', 'categoria' => 'Rodamientos', 'unidad_stock' => 'pza',
        'precio_menor' => '100', 'precio_mayor' => '90',
    ], $cambios);
}

it('importa nuevo y usado en un solo producto con lotes precios y stocks independientes sin duplicar al reintentar', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = importacionConCondiciones();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaConCondicion(['cantidad_inicial' => '8', 'costo_unitario' => '70']),
        filaConCondicion(['fila' => 3, 'condicion' => 'Usado', 'sku' => '0045670-U', 'precio_menor' => '60', 'precio_mayor' => '50', 'cantidad_inicial' => '3', 'costo_unitario' => '25']),
    ], 'variantes.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    expect($importacion->revision['errores'])->toBe([]);
    $servicio->confirmar($importacion->id, $usuario);
    $servicio->ejecutar($importacion->id);
    expect($importacion->refresh()->estado)->toBe('completada');
    $producto = ProductoMaestro::query()->sole();
    expect($producto->codigo_repuesto)->toBe('0045670');
    expect($producto->variantes)->toHaveCount(2);
    $nuevo = $producto->variantes->firstWhere('sku', '0045670-N');
    $usado = $producto->variantes->firstWhere('sku', '0045670-U');
    expect($nuevo->condicion->nombre)->toBe('Nuevo');
    expect($usado->condicion->nombre)->toBe('Usado');
    expect($nuevo->stocks()->sole()->cantidad_actual)->toBe('8.0000');
    expect($usado->stocks()->sole()->cantidad_actual)->toBe('3.0000');
    expect($nuevo->precio_venta)->toBe('100.00');
    expect($usado->precio_venta)->toBe('60.00');
    expect($nuevo->precio_costo)->toBe('70.0000');
    expect($usado->precio_costo)->toBe('25.0000');
    expect(InventarioInicialDetalle::query()->count())->toBe(2);
    expect(MovimientoInventario::query()->count())->toBe(2);
});

it('agrega una variante al producto existente y conserva su condición al omitirla en una actualización', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = importacionConCondiciones();
    $producto = ProductoMaestro::factory()->create(['nombre' => 'Rodamiento 45670', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '0045670']);
    $nuevo = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => '0045670-N', 'condicion_producto_id' => CondicionProducto::query()->where('codigo', 'nuevo')->value('id')]);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaConCondicion(['condicion' => 'Usado', 'sku' => '0045670-U'])], 'usado.xlsx', null, null, false, $usuario);
    expect($importacion->revision['errores'])->toBe([]);
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoMaestro::query()->count())->toBe(1);
    expect($producto->variantes()->count())->toBe(2);
    $actualizacion = $servicio->preparar([filaConCondicion(['condicion' => '', 'codigo_producto' => '', 'precio_menor' => '110'])], 'precio.xlsx', null, null, true, $usuario);
    expect($actualizacion->revision['errores'])->toBe([]);
    $servicio->confirmar($actualizacion->id, $usuario);
    expect($nuevo->refresh()->condicion->nombre)->toBe('Nuevo');
    expect($nuevo->precio_venta)->toBe('110.00');
    expect($producto->refresh()->codigo_repuesto)->toBe('0045670');
});

it('detecta conflictos de datos compartidos y combinaciones repetidas antes de confirmar', function (array $cambios, string $mensaje) {
    ['usuario' => $usuario] = importacionConCondiciones();
    $revision = app(ImportarProductos::class)->revisar([
        filaConCondicion(), filaConCondicion(['fila' => 3, 'sku' => 'OTRO', ...$cambios]),
    ], null, false, $usuario);
    expect(implode(' ', $revision['errores'][3]))->toContain($mensaje);
    expect(ProductoMaestro::query()->exists())->toBeFalse();
})->with([
    'nombre diferente' => [['nombre' => 'Otra pieza', 'condicion' => 'Usado'], 'deben compartir'],
    'condición repetida' => [[], 'Ya hay otro SKU'],
]);

it('deja condiciones vacías desconocidas o inactivas para corregir en la revisión', function (string $valor) {
    ['usuario' => $usuario] = importacionConCondiciones();
    CondicionProducto::factory()->create(['nombre' => 'Desactivado', 'is_active' => false]);
    $revision = app(ImportarProductos::class)->revisar([filaConCondicion(['condicion' => $valor])], null, false, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain('condición');
    expect(ProductoVariante::query()->count())->toBe(0);
})->with(['', 'No existe', 'Desactivado']);

it('permite corregir la condición en lote y por fila sin volver a subir el archivo', function () {
    ['usuario' => $usuario] = importacionConCondiciones();
    $importacion = app(ImportarProductos::class)->preparar([
        filaConCondicion(['condicion' => '']), filaConCondicion(['fila' => 3, 'codigo_producto' => '0045671', 'sku' => '0045671-N', 'condicion' => '']),
    ], 'por-revisar.xlsx', null, null, false, $usuario);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableBulkAction('asignarCampo', ['2', '3'], ['campo' => 'condicion', 'valor' => 'Nuevo'])->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([]);
    $pagina->callTableAction('corregirFila', '3', ['condicion' => 'Usado'])->assertHasNoErrors();
    expect($importacion->refresh()->filas[1]['condicion'])->toBe('Usado');
    expect($importacion->revision['errores'])->toBe([]);
});

it('reconoce los encabezados y conserva ceros del código común y SKU al relacionar un Excel propio', function () {
    importacionConCondiciones();
    $mapeador = app(MapearColumnasProductos::class);
    $archivo = ['columnas' => ['Código del producto', 'Condición', 'SKU interno', 'Nombre del producto'], 'filas' => [
        ['fila' => 2, 'valores' => ['0045670', 'Usado', '0045670-U', 'Rodamiento']],
    ]];
    $mapa = $mapeador->sugerir($archivo['columnas']);
    expect($mapa)->toBe(['codigo_producto', 'condicion', 'sku', 'nombre']);
    expect($mapeador->aplicar($archivo, $mapa)[0])->toMatchArray(['codigo_producto' => '0045670', 'condicion' => 'Usado', 'sku' => '0045670-U']);
    $ruta = tempnam(sys_get_temp_dir(), 'plantilla-condiciones-');
    try {
        app(ArchivoProductos::class)->plantilla($ruta, 'BOB');
        $reader = new Reader;
        $reader->open($ruta);
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === 'Productos') {
                foreach ($sheet->getRowIterator() as $row) {
                    expect(array_slice($row->toArray(), 0, 3))->toBe(['codigo_producto', 'condicion', 'sku']);
                    break;
                }
            }
        }
        $reader->close();
        unset($row, $sheet, $reader);
        gc_collect_cycles();
    } finally {
        unlink($ruta);
    }
});

it('exige permiso de edición para agregar una variante a un producto existente', function () {
    ['categoria' => $categoria] = importacionConCondiciones();
    ProductoMaestro::factory()->create(['nombre' => 'Rodamiento 45670', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '0045670']);
    $usuario = TenantUser::factory()->create();
    $usuario->givePermissionTo('Importar:Productos', 'ViewAny:ProductoMaestro', 'Create:ProductoMaestro');
    expect(fn () => app(ImportarProductos::class)->revisar([filaConCondicion()], null, false, $usuario))->toThrow(AuthorizationException::class);
    expect(ProductoVariante::query()->exists())->toBeFalse();
});

it('admite variantes de la misma condición si su ficha las distingue', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = importacionConCondiciones();
    CategoriaAtributo::factory()->lista(['ZZ', '2RS'])->create(['categoria_id' => $categoria->id, 'clave' => 'sello']);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaConCondicion(['atributos' => '{"sello":"ZZ"}']), filaConCondicion(['fila' => 3, 'sku' => '0045670-N-2RS', 'atributos' => '{"sello":"2RS"}']),
    ], 'sellos.xlsx', null, null, false, $usuario);
    expect($importacion->revision['errores'])->toBe([]);
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoMaestro::query()->sole()->variantes()->count())->toBe(2);
});

it('no adjunta variantes a otro negocio aunque comparta el mismo código', function () {
    ['tenant' => $primero, 'usuario' => $usuario] = importacionConCondiciones();
    tenancy()->end();
    $otro = Tenant::factory()->create();
    tenancy()->initialize($otro);
    $ajeno = ProductoMaestro::factory()->create(['codigo_repuesto' => '0045670']);
    tenancy()->end();
    tenancy()->initialize($primero);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaConCondicion()], 'propio.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoVariante::query()->sole()->producto_maestro_id)->not->toBe($ajeno->id);
});

it('revalida la condición antes de ejecutar una importación en cola', function () {
    ['usuario' => $usuario] = importacionConCondiciones();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaConCondicion()], 'cola.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    CondicionProducto::query()->where('codigo', 'nuevo')->update(['is_active' => false]);
    $servicio->ejecutar($importacion->id);
    expect($importacion->refresh()->estado)->toBe('revision');
    expect($importacion->revision['errores'])->toHaveKey(2);
    expect(ProductoMaestro::query()->exists())->toBeFalse();
});

it('reconoce un código cero existente sin crear otro producto', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = importacionConCondiciones();
    $producto = ProductoMaestro::factory()->create(['nombre' => 'Rodamiento 45670', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '0']);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaConCondicion(['codigo_producto' => '0'])], 'cero.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoMaestro::query()->count())->toBe(1);
    expect($producto->variantes()->count())->toBe(1);
});

it('no mueve un SKU entre productos y pide resolver códigos ambiguos para variantes nuevas', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = importacionConCondiciones();
    $producto = ProductoMaestro::factory()->create(['nombre' => 'Rodamiento 45670', 'categoria_id' => $categoria->id, 'codigo_repuesto' => '0045670']);
    ProductoMaestro::factory()->create(['codigo_repuesto' => '0045670']);
    ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id, 'sku' => '0045670-N', 'condicion_producto_id' => CondicionProducto::query()->where('codigo', 'nuevo')->value('id')]);
    $servicio = app(ImportarProductos::class);
    $ambiguo = $servicio->revisar([filaConCondicion(['sku' => 'NUEVA'])], null, false, $usuario);
    expect(implode(' ', $ambiguo['errores'][2]))->toContain('varios productos');
    $movido = $servicio->revisar([filaConCondicion(['codigo_producto' => 'OTRO'])], null, true, $usuario);
    expect(implode(' ', $movido['errores'][2]))->toContain('desde su ficha');
    expect($servicio->revisar([filaConCondicion()], null, true, $usuario)['errores'])->toBe([]);
});

it('crea una condición desde el selector y la deja seleccionada sin importar productos todavía', function () {
    ['usuario' => $usuario] = importacionConCondiciones();
    $importacion = app(ImportarProductos::class)->preparar([filaConCondicion(['condicion' => 'Reacondicionado'])], 'condicion.xlsx', null, null, false, $usuario);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callAction([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent('condicion'),
        ], ['nombre' => ' Reacondicionado '])->assertHasNoErrors();
    $condicion = CondicionProducto::query()->where('nombre', 'Reacondicionado')->sole();
    expect($condicion->codigo)->toBe('reacondicionado');
    expect($condicion->is_active)->toBeTrue();
    expect($condicion->tenant_id)->toBe((string) tenant('id'));
    expect($pagina->get('mountedActions')[0]['data']['condicion'])->toBe('Reacondicionado');
    expect($importacion->refresh()->revision['errores'])->toBe([]);
    expect(ProductoMaestro::query()->exists())->toBeFalse();
    expect(MovimientoInventario::query()->exists())->toBeFalse();
    $pagina->callMountedAction()->assertHasNoErrors();
    expect($importacion->refresh()->filas[0]['condicion'])->toBe('Reacondicionado');
});

it('rechaza nombres equivalentes o sin identificador válido al crear una condición', function (string $nombre, string $mensaje) {
    ['usuario' => $usuario] = importacionConCondiciones();
    $importacion = app(ImportarProductos::class)->preparar([filaConCondicion()], 'duplicado.xlsx', null, null, false, $usuario);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callAction([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent('condicion'),
        ], ['nombre' => $nombre])
        ->assertHasErrors(['mountedActions.1.data.nombre']);
    expect($pagina->instance()->getErrorBag()->first('mountedActions.1.data.nombre'))->toContain($mensaje);
    expect(CondicionProducto::query()->count())->toBe(2);
})->with([
    'mayúsculas y espacios' => [' nUeVo ', 'Ya existe una condición equivalente.'],
    'identificador equivalente' => ['Nuévo', 'Ya existe una condición equivalente.'],
    'sin letras ni números' => ['!!!', 'Ingresá un nombre con letras o números.'],
]);

it('oculta y protege la creación de condiciones sin permiso para crear productos', function () {
    ['usuario' => $usuario] = importacionConCondiciones();
    $importacion = app(ImportarProductos::class)->preparar([filaConCondicion()], 'sin-permiso.xlsx', null, null, false, $usuario);
    $usuario->syncRoles([]);
    $usuario->givePermissionTo(['Importar:Productos', 'ViewAny:ProductoMaestro']);
    $acciones = [TestAction::make('corregirFila')->table('2'), TestAction::make('createOption')->schemaComponent('condicion')];
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->assertActionHidden($acciones)
        ->mountAction($acciones)->assertCount('mountedActions', 1);
    expect(CondicionProducto::query()->count())->toBe(2);
});

it('permite crear la misma condición en otro negocio sin reutilizar el registro ajeno', function () {
    ['tenant' => $tenant, 'usuario' => $usuario] = importacionConCondiciones();
    tenancy()->end();
    $otro = Tenant::factory()->create();
    tenancy()->initialize($otro);
    $ajena = CondicionProducto::factory()->create(['nombre' => 'Reacondicionado', 'codigo' => 'reacondicionado']);
    tenancy()->end();
    tenancy()->initialize($tenant);
    $importacion = app(ImportarProductos::class)->preparar([filaConCondicion(['condicion' => 'Reacondicionado'])], 'propia.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callAction([
            TestAction::make('corregirFila')->table('2'), TestAction::make('createOption')->schemaComponent('condicion'),
        ], ['nombre' => 'Reacondicionado'])->assertHasNoErrors();
    $propia = CondicionProducto::query()->where('nombre', 'Reacondicionado')->sole();
    expect($propia->id)->not->toBe($ajena->id);
    expect($propia->tenant_id)->toBe($tenant->id);
});
