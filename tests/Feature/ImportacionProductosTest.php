<?php

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos;
use App\Catalogo\MapearColumnasProductos;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ImportarProductos as ImportarPagina;
use App\Inventario\AjustarInventario;
use App\Jobs\ProcesarImportacionProductos;
use App\Livewire\Negocio\RevisionImportacionProductos;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\ImportacionProducto;
use App\Models\InventarioInicialDetalle;
use App\Models\Marca;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::connection('pool_shared_1')->dropAllTables();
    $this->artisan('migrate', ['--database' => 'pool_shared_1', '--path' => database_path('migrations/tenant'), '--realpath' => true, '--force' => true]);
});

afterEach(function () {
    if (config('filesystems.disks.tmp-for-tests')) {
        Storage::disk('tmp-for-tests')->deleteDirectory('livewire-tmp');
    }
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

function escenarioImportacion(): array
{
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    $categoria = Categoria::factory()->create(['nombre' => 'Rodamientos']);
    $sucursal = Sucursal::factory()->create();
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    return compact('tenant', 'usuario', 'categoria', 'sucursal', 'almacen');
}

function filaImportacion(array $cambios = []): array
{
    return array_replace(array_fill_keys(ArchivoProductos::COLUMNAS, ''), [
        'fila' => 2, 'sku' => '0006206', 'condicion' => 'Nuevo', 'nombre' => 'Rodamiento 6206', 'categoria' => 'Rodamientos',
        'unidad_stock' => 'pza', 'codigo_barras' => '001234567890', 'precio_menor' => '45', 'precio_mayor' => '40',
    ], $cambios);
}

function excelImportacion(array $filas, array $columnas = ArchivoProductos::COLUMNAS): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'test-productos-');
    $writer = new Writer;
    try {
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues($columnas));
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues(array_map(fn (string $columna) => $fila[$columna], $columnas)));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('productos.xlsx', file_get_contents($ruta));
    } finally {
        unlink($ruta);
    }
}

it('recorre la carga Excel y crea dos lotes del mismo SKU con costo y proveedor en el kardex', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'Proveedor conocido']);
    $archivo = excelImportacion([
        filaImportacion(['cantidad_inicial' => '10', 'costo_unitario' => '28', 'proveedor' => $proveedor->nombre_empresa]),
        filaImportacion(['fila' => 3, 'cantidad_inicial' => '5', 'costo_unitario' => '30']),
    ]);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => $archivo, 'inventario' => true, 'almacen_id' => $almacen->id, 'fecha_inventario' => today()->format('Y-m-d')])
        ->call('revisar')->assertHasNoErrors()->assertSee('430.00');
    expect(ProductoVariante::query()->count())->toBe(0);
    $pagina->call('confirmar')->assertHasNoErrors()->assertSee('Importación completada');
    $variante = ProductoVariante::query()->sole();
    expect($variante->sku)->toBe('0006206')->and($variante->codigo_barras)->toBe('001234567890')
        ->and($variante->precio_venta)->toBe('45.00')->and($variante->precio_mayor)->toBe('40.00')
        ->and($variante->precio_costo)->toBe('28.6667')
        ->and($variante->stocks()->where('almacen_id', $almacen->id)->sole()->cantidad_actual)->toBe('15.0000');
    expect(InventarioInicialDetalle::query()->orderBy('fila')->pluck('proveedor_id')->all())->toBe([$proveedor->id, null]);
    expect(MovimientoInventario::query()->pluck('tipo_movimiento')->all())->toBe([TipoMovimientoInventario::InventarioInicial, TipoMovimientoInventario::InventarioInicial]);
    expect(OrdenCompra::query()->count())->toBe(0);
});

it('despacha a la cola y un reintento no duplica catálogo lotes ni stock', function () {
    ['tenant' => $tenant, 'usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $filas = [filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '25'])];
    $importacion = $servicio->preparar($filas, 'inicial.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    Queue::assertPushed(ProcesarImportacionProductos::class, 1);
    expect(ProductoVariante::query()->count())->toBe(0);
    $job = new ProcesarImportacionProductos($tenant->id, $importacion->id);
    $job->handle($servicio);
    $job->handle($servicio);
    expect(ProductoVariante::query()->count())->toBe(1)->and(InventarioInicialDetalle::query()->count())->toBe(1)
        ->and(MovimientoInventario::query()->count())->toBe(1);
    expect($servicio->preparar($filas, 'otro-nombre.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario)->id)->toBe($importacion->id);
});

it('importa solo catálogo y conserva existentes salvo actualización explícita', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $primera = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $servicio->confirmar($primera->id, $usuario);
    $segunda = $servicio->preparar([filaImportacion(['precio_menor' => '50'])], 'precios.xlsx', null, null, false, $usuario);
    $servicio->confirmar($segunda->id, $usuario);
    expect(ProductoVariante::query()->sole()->precio_venta)->toBe('45.00')->and(MovimientoInventario::query()->count())->toBe(0);
    $tercera = $servicio->preparar([filaImportacion(['precio_menor' => '55', 'precio_mayor' => ''])], 'actualizar.xlsx', null, null, true, $usuario);
    $servicio->confirmar($tercera->id, $usuario);
    expect(ProductoVariante::query()->sole()->precio_venta)->toBe('55.00')->and(ProductoVariante::query()->sole()->precio_mayor)->toBe('40.00');
});

it('rechaza las filas incorrectas antes de crear productos', function (array $cambios, string $mensaje) {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $revision = app(ImportarProductos::class)->revisar([filaImportacion($cambios)], $almacen->id, false, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain($mensaje);
    expect(ProductoMaestro::query()->count())->toBe(0);
})->with([
    'costo obligatorio' => [['cantidad_inicial' => '2'], 'costo unitario'],
    'cantidad negativa' => [['cantidad_inicial' => '-1', 'costo_unitario' => '20'], 'cantidad inicial'],
    'SKU faltante' => [['sku' => ''], 'Ingresá el SKU'],
    'nombre faltante' => [['nombre' => ''], 'Completá el nombre del producto'],
    'piezas fraccionadas' => [['cantidad_inicial' => '1.5', 'costo_unitario' => '20'], 'unidad de stock'],
    'unidad de atributo' => [['unidad_stock' => 'mm'], 'símbolo de unidad'],
    'precio mayor' => [['precio_mayor' => '60'], 'Mayor supera'],
    'proveedor desconocido' => [['cantidad_inicial' => '2', 'costo_unitario' => '20', 'proveedor' => 'Ajeno'], 'proveedor existente'],
]);

it('muestra los errores en la revisión y bloquea la confirmación completa', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => excelImportacion([filaImportacion(), filaImportacion(['sku' => 'OTRO', 'precio_menor' => '-5'])])])
        ->call('revisar')->assertHasNoErrors()->assertSee('filas necesitan corrección')
        ->call('confirmar')->assertHasErrors(['archivo']);
    expect(ProductoVariante::query()->count())->toBe(0);
    $pagina->call('descargarErrores')->assertFileDownloaded('productos-por-completar.xlsx');
});

it('requiere revisar otra vez si el catálogo cambió durante la espera en cola', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    ProductoVariante::factory()->for(ProductoMaestro::factory()->create(['nombre' => 'Otro nombre', 'categoria_id' => $categoria->id]), 'productoMaestro')
        ->create(['sku' => '0006206', 'codigo_barras' => '001234567890']);
    $servicio->ejecutar($importacion->id);
    expect($importacion->refresh()->estado)->toBe('revision')->and($importacion->resultado['aviso'])->toContain('cambiaron');
    expect(ProductoVariante::query()->count())->toBe(1);
});

it('exporta los pendientes con atributos faltantes y conserva las correcciones y los lotes sin aplicarlos', function () {
    ['usuario' => $usuario, 'categoria' => $categoria, 'almacen' => $almacen] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Correas']);
    $milimetros = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $diametro = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro', 'unidad_medida_id' => $milimetros->id, 'obligatorio' => true]);
    $ancho = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'ancho', 'etiqueta' => 'Ancho', 'unidad_medida_id' => $milimetros->id, 'obligatorio' => true]);
    $sello = CategoriaAtributo::factory()->lista()->create(['categoria_id' => $categoria->id, 'clave' => 'sello', 'etiqueta' => 'Sello']);
    $largo = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $otra->id, 'clave' => 'largo', 'etiqueta' => 'Largo', 'unidad_medida_id' => $milimetros->id, 'obligatorio' => true]);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '20', 'atributos' => '{"diametro":5.5,"sello":["ZZ"]}']),
        filaImportacion(['fila' => 8, 'sku' => 'VALIDO', 'codigo_barras' => '', 'atributos' => '{"diametro":6,"ancho":1}']),
        filaImportacion(['fila' => 15, 'sku' => 'CORREA', 'categoria' => 'Correas', 'codigo_barras' => '', 'precio_menor' => '-1', '_ficha_columnas' => json_encode(['ficha_'.$largo->id => '12'])]),
    ], 'pendientes.xlsx', $almacen->id, today()->toDateString(), false, $usuario);
    $importacion = $servicio->corregirFilas($importacion->id, [2], ['nombre' => 'Producto corregido'], $usuario);
    $filasGuardadas = $importacion->filas;
    $ruta = tempnam(sys_get_temp_dir(), 'pendientes-test-');

    try {
        app(ArchivoProductos::class)->pendientes($ruta, $filasGuardadas, $importacion->revision['errores'], $importacion->codigo());
        $contenido = app(ArchivoProductos::class)->leer($ruta);
        expect(array_column($contenido, 'sku'))->toBe(['0006206', 'CORREA']);
        expect($contenido[0])->toMatchArray(['nombre' => 'Producto corregido', 'codigo_barras' => '001234567890', 'cantidad_inicial' => '2', 'costo_unitario' => '20', 'atributos' => '']);
        expect(json_decode($contenido[0]['_ficha_columnas'], true))->toBe([
            'ficha_'.$diametro->id => '5.5', 'ficha_'.$ancho->id => '', 'ficha_'.$sello->id => 'ZZ', 'ficha_'.$largo->id => '',
        ]);
        expect(json_decode($contenido[1]['_ficha_columnas'], true)['ficha_'.$largo->id])->toBe('12');

        $reader = new Reader;
        $reader->open($ruta);
        $hojas = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $hojas[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
        }
        expect($hojas['Qué completar'][1])->toBe(['2', '2', '0006206', 'Ancho es obligatorio.']);
        expect($hojas['Qué completar'][2][0])->toBe('3');
        expect($hojas['Qué completar'][2][1])->toBe('15');
        expect($hojas['Ficha por categoría'])->toContain(['Rodamientos', 'Ancho [mm] · Rodamientos', 'Obligatorio', 'Decimal', 'mm', 'Solo número; punto o coma decimal. Ejemplo: 5,5.']);
        $contenido[0]['_ficha_columnas'] = json_encode(['ficha_'.$diametro->id => '5.5', 'ficha_'.$ancho->id => '2', 'ficha_'.$sello->id => 'ZZ']);
        $contenido[1]['precio_menor'] = '45';
        expect($servicio->revisar($contenido, $almacen->id, false, $usuario)['errores'])->toBe([]);
        expect($importacion->refresh()->filas)->toBe($filasGuardadas);
        expect(ProductoVariante::query()->count())->toBe(0);
        expect(MovimientoInventario::query()->count())->toBe(0);
    } finally {
        unset($sheet, $reader);
        gc_collect_cycles();
        unlink($ruta);
    }
});

it('conserva el JSON inválido y las columnas de ficha sin correspondencia al descargar pendientes', function () {
    escenarioImportacion();
    $ruta = tempnam(sys_get_temp_dir(), 'pendientes-compatibilidad-');
    $fila = filaImportacion(['categoria' => 'Todavía no existe', 'atributos' => '{json inválido', '_ficha_columnas' => '{"ficha_999999":"005"}']);

    try {
        app(ArchivoProductos::class)->pendientes($ruta, [$fila], [2 => ['Corregí la categoría.']], 'IMP-000001');
        $contenido = app(ArchivoProductos::class)->inspeccionar($ruta);
        expect($contenido['columnas'])->toContain('ficha_999999');
        $valores = array_combine($contenido['columnas'], $contenido['filas'][0]['valores']);
        expect($valores['atributos'])->toBe('{json inválido');
        expect($valores['ficha_999999'])->toBe('005');
    } finally {
        unlink($ruta);
    }
});

it('rechaza una descarga de pendientes vacía o que no puede volver a importarse por tamaño', function (bool $demasiadasColumnas, string $mensaje) {
    escenarioImportacion();
    $ruta = tempnam(sys_get_temp_dir(), 'pendientes-limite-');
    $fila = filaImportacion(['_ficha_columnas' => json_encode(array_fill_keys(array_map(fn (int $numero): string => 'ficha_'.$numero, range(1, 89)), ''))]);

    try {
        app(ArchivoProductos::class)->pendientes($ruta, [$fila], $demasiadasColumnas ? [2 => ['Pendiente']] : [], 'IMP-000001');
    } catch (ValidationException $exception) {
        expect($exception->errors()['archivo'][0])->toContain($mensaje);

        return;
    } finally {
        unlink($ruta);
    }
    $this->fail('La descarga debía rechazar el archivo.');
})->with([
    'sin pendientes' => [false, 'no tiene filas pendientes'],
    'más de cien columnas' => [true, 'superan las 100 columnas'],
]);

it('revierte toda la importación si falla un movimiento intermedio', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaImportacion(['cantidad_inicial' => '10', 'costo_unitario' => '28']),
        filaImportacion(['fila' => 3, 'cantidad_inicial' => '5', 'costo_unitario' => '30']),
    ], 'lotes.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $intentos = 0;
    Event::listen('eloquent.creating: '.MovimientoInventario::class, function () use (&$intentos): void {
        if (++$intentos === 2) {
            throw new RuntimeException('Fallo de escritura');
        }
    });
    expect(fn () => $servicio->ejecutar($importacion->id))->toThrow(RuntimeException::class, 'Fallo de escritura');
    expect(ProductoVariante::query()->count())->toBe(0)->and(ProductoMaestro::query()->count())->toBe(0)
        ->and(InventarioInicialDetalle::query()->count())->toBe(0)->and(MovimientoInventario::query()->count())->toBe(0);
});

it('impide cargar inicial sobre movimientos existentes y respeta conteos abiertos', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '25'])], 'inicial.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $revision = $servicio->revisar([filaImportacion(['cantidad_inicial' => '3', 'costo_unitario' => '25'])], $almacen->id, false, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain('ya tiene movimientos');
    app(AjustarInventario::class)->abrirConteo($almacen->id, [['producto_variante_id' => ProductoVariante::query()->sole()->id, 'cantidad_fisica' => '2']], $usuario);
    expect(fn () => $servicio->revisar([filaImportacion()], $almacen->id, false, $usuario))->toThrow(ValidationException::class, 'conteo abierto');
});

it('deniega vendedores y almacenes fuera de las sucursales asignadas', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $vendedor = TenantUser::factory()->create();
    $vendedor->assignRole('vendedor');
    Livewire::actingAs($vendedor, 'tenant')->test(ImportarPagina::class)->assertForbidden();
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');
    expect(fn () => app(ImportarProductos::class)->revisar([filaImportacion()], Almacen::factory()->create()->id, false, $supervisor))
        ->toThrow(ValidationException::class, 'fuera de tus sucursales');
});

it('descarga la plantilla con catálogos y rechaza fórmulas del archivo', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)->call('descargarPlantilla')->assertFileDownloaded('plantilla-productos.xlsx');
    $excel = excelImportacion([filaImportacion(['nombre' => '=1+1'])]);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)->fillForm(['archivo' => $excel])
        ->call('revisar')->assertHasErrors(['archivo']);
});

it('restaura el tenant del trabajo aunque el proceso esté atendiendo otro negocio', function () {
    ['tenant' => $primero, 'usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '25'])], 'inicial.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $segundo = Tenant::factory()->create();
    tenancy()->initialize($segundo);
    (new ProcesarImportacionProductos($primero->id, $importacion->id))->handle($servicio);
    expect((string) tenant('id'))->toBe($segundo->id)->and(ProductoVariante::query()->count())->toBe(0);
    $ajeno = TenantUser::factory()->create();
    $ajeno->assignRole('administrador');
    Livewire::actingAs($ajeno, 'tenant')->test(ImportarPagina::class)
        ->call('verImportacion', $importacion->id)->assertNotFound();
    tenancy()->initialize($primero);
    expect(ProductoVariante::query()->sole()->tenant_id)->toBe($primero->id);
});

it('vuelve a verificar los permisos cuando se ejecuta la cola', function () {
    ['tenant' => $tenant, 'usuario' => $usuario] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $usuario->syncRoles(['vendedor']);
    (new ProcesarImportacionProductos($tenant->id, $importacion->id))->handle($servicio);
    expect($importacion->refresh()->estado)->toBe('fallida')->and(ProductoVariante::query()->count())->toBe(0);
});

it('valida la ficha técnica obligatoria y conserva las referencias USD existentes', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro', 'obligatorio' => true]);
    $servicio = app(ImportarProductos::class);
    $revision = $servicio->revisar([filaImportacion()], null, false, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain('Diámetro es obligatorio');
    $importacion = $servicio->preparar([filaImportacion(['atributos' => '{"diametro":5}'])], 'ficha.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $variante = ProductoVariante::query()->sole();
    expect($variante->atributos_json['diametro'])->toBe(5);
    $variante->update(['precio_venta_dolar' => '5']);
    $revision = $servicio->revisar([filaImportacion(['atributos' => '{"diametro":5}'])], null, true, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain('referencia en USD');
});

it('rechaza identidades contradictorias y códigos de barras compartidos entre distintos SKU', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $revision = app(ImportarProductos::class)->revisar([
        filaImportacion(['cantidad_inicial' => '1', 'costo_unitario' => '20']),
        filaImportacion(['fila' => 3, 'cantidad_inicial' => '1', 'costo_unitario' => '22', 'precio_menor' => '55']),
        filaImportacion(['fila' => 4, 'sku' => 'OTRO']),
    ], $almacen->id, false, $usuario);
    expect(implode(' ', $revision['errores'][3]))->toContain('SKU repetido');
    expect(implode(' ', $revision['errores'][4]))->toContain('código de barras');
});

it('permite reintentar cuando falla el envío a la cola', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Cola temporalmente fuera de servicio'));
    expect(fn () => $servicio->confirmar($importacion->id, $usuario))->toThrow(ValidationException::class, 'No se pudo iniciar');
    expect($importacion->refresh()->estado)->toBe('fallida')->and(ProductoVariante::query()->count())->toBe(0);
});

it('deja recuperable la carga si el negocio fue desactivado mientras esperaba en cola', function () {
    ['tenant' => $tenant, 'usuario' => $usuario] = escenarioImportacion();
    Queue::fake();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    $tenant->update(['is_active' => false]);
    $job = new ProcesarImportacionProductos($tenant->id, $importacion->id);
    $job->handle($servicio);
    expect($importacion->refresh()->estado)->toBe('fallida')->and(ProductoVariante::query()->count())->toBe(0);
    $tenant->update(['is_active' => true]);
    $servicio->confirmar($importacion->id, $usuario);
    $job->handle($servicio);
    expect($importacion->refresh()->estado)->toBe('completada')->and(ProductoVariante::query()->count())->toBe(1);
});

it('corrige categoría y proveedor desde los selectores de la fila y recalcula la revisión', function () {
    ['usuario' => $usuario, 'almacen' => $almacen, 'categoria' => $categoria] = escenarioImportacion();
    $proveedor = Proveedor::factory()->create(['nombre_empresa' => 'Proveedor seleccionado']);
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion(['categoria' => 'Nombre del Excel', 'cantidad_inicial' => '4', 'costo_unitario' => '20'])], 'corregir.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->assertSee('La categoría «Nombre del Excel»')
        ->call('updateTableColumnState', 'categoria', '2', $categoria->nombre)
        ->assertHasNoErrors()->assertDontSee('La categoría «Nombre del Excel»')
        ->call('updateTableColumnState', 'proveedor', '2', $proveedor->nombre_empresa)
        ->assertHasNoErrors()->assertDispatched('importacion-corregida');
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and($importacion->revision['valor'])->toBe('80.0000')
        ->and($importacion->filas[0]['proveedor'])->toBe($proveedor->nombre_empresa)
        ->and(ProductoVariante::query()->count())->toBe(0);
    $servicio->confirmar($importacion->id, $usuario);
    expect(InventarioInicialDetalle::query()->sole()->proveedor_id)->toBe($proveedor->id);
});

it('aplica una categoría a filas seleccionadas de distintas páginas y conserva las demás', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $filas = [];
    for ($indice = 0; $indice < 32; $indice++) {
        $filas[] = filaImportacion(['fila' => $indice + 2, 'sku' => 'SKU-'.$indice, 'codigo_barras' => '', 'nombre' => 'Producto '.$indice, 'categoria' => 'Categoría del Excel']);
    }
    $importacion = app(ImportarProductos::class)->preparar($filas, 'muchos.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->assertCountTableRecords(32)
        ->callTableBulkAction('asignarCampo', ['2', '30'], ['campo' => 'categoria', 'valor' => $categoria->nombre])
        ->assertHasNoErrors();
    $guardadas = collect($importacion->refresh()->filas)->keyBy('fila');
    expect($guardadas[2]['categoria'])->toBe($categoria->nombre)
        ->and($guardadas[30]['categoria'])->toBe($categoria->nombre)
        ->and($guardadas[3]['categoria'])->toBe('Categoría del Excel')
        ->and(count($importacion->revision['errores']))->toBe(30);
});

it('selecciona todas las filas filtradas conservando las desmarcadas al aplicar en grupo', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $filas = [];
    for ($indice = 0; $indice < 32; $indice++) {
        $filas[] = filaImportacion(['fila' => $indice + 2, 'sku' => 'SKU-'.$indice, 'codigo_barras' => '', 'nombre' => $indice === 31 ? 'Otra familia' : 'Filtro producto '.$indice, 'categoria' => 'Sin resolver']);
    }
    $importacion = app(ImportarProductos::class)->preparar($filas, 'filtrar.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->searchTable('Filtro')->assertCountTableRecords(31)
        ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', ['2'])
        ->callTableBulkAction('asignarCampo', [], ['campo' => 'categoria', 'valor' => $categoria->nombre])
        ->assertHasNoErrors();
    $guardadas = collect($importacion->refresh()->filas)->keyBy('fila');
    expect($guardadas[2]['categoria'])->toBe('Sin resolver')
        ->and($guardadas[33]['categoria'])->toBe('Sin resolver')
        ->and($guardadas[30]['categoria'])->toBe($categoria->nombre)
        ->and(count($importacion->revision['errores']))->toBe(2);
});

it('permite corregir cantidades y costos en el modal individual sin volver a subir el Excel', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['cantidad_inicial' => '5', 'costo_unitario' => 'pendiente'])], 'costo.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableAction('corregirFila', '2', ['cantidad_inicial' => '3', 'costo_unitario' => '28'])
        ->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and($importacion->revision['valor'])->toBe('84.0000')
        ->and($importacion->filas[0]['sku'])->toBe('0006206');
});

it('protege los borradores ajenos y las cargas confirmadas ante correcciones', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $otro = TenantUser::factory()->create();
    $otro->assignRole('administrador');
    Livewire::actingAs($otro, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])->assertNotFound();
    expect(fn () => $servicio->corregirFilas($importacion->id, [999], ['categoria' => 'Otra'], $usuario))->toThrow(ValidationException::class, 'no pertenece');
    $servicio->confirmar($importacion->id, $usuario);
    expect(fn () => $servicio->corregirFilas($importacion->id, [2], ['precio_menor' => '55'], $usuario))->toThrow(ValidationException::class, 'confirmada o en proceso');
    expect(ProductoVariante::query()->sole()->precio_venta)->toBe('45.00');
});

it('pide revisar los nuevos importes si otra pestaña corrigió la carga antes de confirmar', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'catalogo.xlsx', null, null, false, $usuario);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->call('verImportacion', $importacion->id);
    $servicio->corregirFilas($importacion->id, [2], ['precio_menor' => '55'], $usuario);
    $pagina->call('confirmar')->assertHasErrors(['archivo'])->assertSee('La revisión cambió');
    expect(ProductoVariante::query()->count())->toBe(0);
});

it('asigna y quita el proveedor de varios lotes desde la acción masiva', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $proveedor = Proveedor::factory()->create();
    $filas = [
        filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '20', 'proveedor' => 'Sin coincidencia']),
        filaImportacion(['fila' => 3, 'cantidad_inicial' => '3', 'costo_unitario' => '22', 'proveedor' => 'Sin coincidencia']),
    ];
    $importacion = app(ImportarProductos::class)->preparar($filas, 'proveedores.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableBulkAction('asignarCampo', ['2', '3'], ['campo' => 'proveedor', 'valor' => $proveedor->nombre_empresa])
        ->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and(collect($importacion->filas)->pluck('proveedor')->all())->toBe([$proveedor->nombre_empresa, $proveedor->nombre_empresa]);
    $tabla->callTableBulkAction('asignarCampo', ['2', '3'], ['campo' => 'proveedor', 'quitar' => true])->assertHasNoErrors();
    expect(collect($importacion->refresh()->filas)->pluck('proveedor')->all())->toBe(['', '']);
});

it('relaciona un Excel propio y confirma atributos decimales y listas sin JSON', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro', 'obligatorio' => true]);
    CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create(['categoria_id' => $categoria->id, 'clave' => 'sellos', 'etiqueta' => 'Sellos']);
    $ruta = tempnam(sys_get_temp_dir(), 'excel-propio-');
    $writer = new Writer;
    try {
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(['Producto', 'Código', 'Familia', 'Unidad', 'Precio', 'Diámetro', 'Sellos', 'Nota personal', 'Condición']));
        $writer->addRow(ArchivoProductos::filaDeTexto(['Rodamiento', '000008', 'Rodamientos', 'pza', '45', '5,5', '2RS|ZZ', 'No importar', 'Nuevo']));
        $writer->close();
        $archivo = UploadedFile::fake()->createWithContent('mi-catalogo.xlsx', file_get_contents($ruta));
    } finally {
        unlink($ruta);
    }
    $mapeador = app(MapearColumnasProductos::class);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => $archivo])->call('revisar')->assertHasNoErrors()->assertSee('Relacionar columnas');
    expect(ProductoVariante::query()->count())->toBe(0);
    $mapa = $pagina->get('columnasData')['mapa'];
    $mapa[2] = 'categoria';
    $mapa[7] = 'ignorar';
    $pagina->set('columnasData.mapa', $mapa)->call('relacionarColumnas')->assertHasNoErrors()->call('confirmar')->assertHasNoErrors();

    $variante = ProductoVariante::query()->sole();
    expect($variante->sku)->toBe('000008')->and($variante->atributos_json)->toBe(['diametro' => 5.5, 'sellos' => ['2RS', 'ZZ']]);
});

it('exige omitir columnas desconocidas y rechaza destinos repetidos o ajenos', function () {
    escenarioImportacion();
    $mapeador = app(MapearColumnasProductos::class);
    $archivo = ['columnas' => ['SKU', 'Nota'], 'filas' => [['fila' => 2, 'valores' => ['ABC', 'privado']]]];

    expect(fn () => $mapeador->aplicar($archivo, ['sku', '']))->toThrow(ValidationException::class, 'Elegí un campo')
        ->and(fn () => $mapeador->aplicar($archivo, ['sku', 'sku']))->toThrow(ValidationException::class, 'sin repetirlo')
        ->and(fn () => $mapeador->aplicar($archivo, ['sku', 'ficha_999999']))->toThrow(ValidationException::class)
        ->and(fn () => $mapeador->aplicar($archivo, ['ignorar', 'nombre']))->toThrow(ValidationException::class, 'SKU');
    expect($mapeador->aplicar($archivo, ['sku', 'ignorar'])[0]['sku'])->toBe('ABC');
});

it('valida atributos por categoría y permite completar la ficha desde el panel lateral', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Correas']);
    $diametro = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro', 'obligatorio' => true]);
    $largo = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $otra->id, 'clave' => 'largo', 'etiqueta' => 'Largo', 'obligatorio' => true]);
    $mapeador = app(MapearColumnasProductos::class);
    $archivo = ['columnas' => ['sku', 'nombre', 'categoria', 'unidad_stock', 'precio_menor', 'Diámetro', 'Largo', 'condicion'], 'filas' => [
        ['fila' => 2, 'valores' => ['ROD', 'Rodamiento', 'Rodamientos', 'pza', '45', 'incorrecto', '', 'Nuevo']],
        ['fila' => 3, 'valores' => ['COR', 'Correa', 'Correas', 'pza', '40', '', '15', 'Nuevo']],
    ]];
    $filas = $mapeador->aplicar($archivo, $mapeador->sugerir($archivo['columnas']));
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar($filas, 'mixto.xlsx', null, null, false, $usuario);
    expect(array_keys($importacion->revision['errores']))->toBe([2]);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableAction('corregirFila', '2', ['ficha' => ['diametro' => '8.5']])->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and($importacion->filas[0])->not->toHaveKey('_ficha_columnas');
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoVariante::query()->where('sku', 'ROD')->sole()->atributos_json)->toBe(['diametro' => 8.5])
        ->and(ProductoVariante::query()->where('sku', 'COR')->sole()->atributos_json)->toBe(['largo' => 15]);
});

it('no mezcla atributos homónimos con unidades distintas ni descarta valores de otra categoría', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Otra categoría']);
    $milimetros = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $otraUnidad = UnidadMedida::factory()->create();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'medida', 'etiqueta' => 'Medida', 'unidad_medida_id' => $milimetros->id]);
    $segundo = CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $otra->id, 'clave' => 'medida', 'etiqueta' => 'Medida', 'unidad_medida_id' => $otraUnidad->id]);
    $mapeador = app(MapearColumnasProductos::class);
    expect($mapeador->sugerir(['medida']))->toBe(['']);
    $revision = app(ImportarProductos::class)->revisar([filaImportacion(['_ficha_columnas' => json_encode(['ficha_'.$segundo->id => 'dato'])])], null, false, $usuario);
    expect(implode(' ', $revision['errores'][2]))->toContain('no corresponde a la categoría');
});

it('comparte una columna de lista entre categorías y valida las opciones de cada una', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Correas']);
    $definicion = CategoriaAtributo::factory()->lista(['Rojo', 'Azul'])->create(['categoria_id' => $categoria->id, 'clave' => 'color', 'etiqueta' => 'Color']);
    CategoriaAtributo::factory()->lista(['Negro', 'Blanco'])->create(['categoria_id' => $otra->id, 'clave' => 'color', 'etiqueta' => 'Color']);
    $mapa = app(MapearColumnasProductos::class)->sugerir(['Color']);
    expect($mapa)->toBe(['ficha_'.$definicion->id]);
    $servicio = app(ImportarProductos::class);
    $filas = [
        filaImportacion(['_ficha_columnas' => json_encode([$mapa[0] => 'Rojo'])]),
        filaImportacion(['fila' => 3, 'sku' => 'COR', 'codigo_barras' => '', 'categoria' => 'Correas', '_ficha_columnas' => json_encode([$mapa[0] => 'Negro'])]),
    ];
    expect($servicio->revisar($filas, null, false, $usuario)['errores'])->toBe([]);
    $filas[1]['_ficha_columnas'] = json_encode([$mapa[0] => 'Rojo']);
    expect(array_keys($servicio->revisar($filas, null, false, $usuario)['errores']))->toBe([3]);
});

it('muestra juntos los catálogos incorrectos en lugar de esconderlos detrás del primero', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $revision = app(ImportarProductos::class)->revisar([filaImportacion([
        'categoria' => 'Inexistente', 'marca' => 'Sin marca registrada', 'unidad_stock' => 'no-existe',
        'proveedor' => 'Sin proveedor registrado', 'cantidad_inicial' => '2', 'costo_unitario' => '20',
    ])], $almacen->id, false, $usuario);

    expect($revision['errores'][2])->toHaveCount(4)->and($revision['nuevos'])->toBe(0);
});

it('crea una marca con autorización y la asigna a todas las coincidencias del Excel', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaImportacion(['marca' => 'Marca nueva']),
        filaImportacion(['fila' => 3, 'sku' => 'ABC', 'codigo_barras' => '', 'marca' => 'MARCA NUEVA']),
    ], 'marcas.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableAction('resolverCatalogos', data: ['campo' => 'marca', 'origen' => 'Marca nueva', 'crear' => true])->assertHasNoErrors();
    expect(Marca::query()->where('nombre', 'Marca nueva')->count())->toBe(1)
        ->and($importacion->refresh()->revision['errores'])->toBe([])
        ->and(collect($importacion->filas)->pluck('marca')->all())->toBe(['Marca nueva', 'Marca nueva'])
        ->and(ProductoVariante::query()->count())->toBe(0);
});

it('rechaza crear catálogos sin permiso aunque el usuario pueda corregir su carga', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion(['marca' => 'Sin permiso'])], 'marca.xlsx', null, null, false, $usuario);
    $usuario->syncRoles([]);
    $usuario->givePermissionTo(['Importar:Productos', 'ViewAny:ProductoMaestro']);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableAction('resolverCatalogos', data: ['campo' => 'marca', 'origen' => 'Sin permiso', 'crear' => true])->assertForbidden();
    expect(Marca::query()->where('nombre', 'Sin permiso')->exists())->toBeFalse();
});

it('genera una plantilla personalizada legible que vuelve a reconocer sus atributos', function () {
    ['categoria' => $categoria] = escenarioImportacion();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro']);
    $ruta = tempnam(sys_get_temp_dir(), 'plantilla-ficha-');
    try {
        app(ArchivoProductos::class)->plantilla($ruta, 'BOB', [$categoria->id], ['nombre', 'precio_menor']);
        $reader = new Reader;
        $reader->open($ruta);
        $columnas = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $columnas = $row->toArray();
                break;
            }
            break;
        }
        $reader->close();
        unset($sheet, $reader);
        gc_collect_cycles();
        $mapa = app(MapearColumnasProductos::class)->sugerir($columnas);
        expect($columnas)->toHaveCount(4)->not->toContain('atributos')->and($mapa[0])->toBe('sku')->and($mapa[3])->toStartWith('ficha_');
    } finally {
        unlink($ruta);
    }
});

it('muestra los atributos de las categorías elegidas antes de descargar un solo Excel', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Filtros']);
    $sinFicha = Categoria::factory()->create(['nombre' => 'General']);
    $milimetros = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro interno', 'unidad_medida_id' => $milimetros->id, 'obligatorio' => true]);
    CategoriaAtributo::factory()->lista(['Papel', 'Metal'])->create(['categoria_id' => $otra->id, 'clave' => 'material', 'etiqueta' => 'Material filtrante']);

    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->mountAction('plantilla')->call('forceRender')
        ->assertSee('Se descargará un solo Excel')->assertSee('Plantilla básica, sin columnas de ficha técnica.')
        ->set('mountedActions.0.data.categorias', [$categoria->id])->call('forceRender')
        ->assertSee('Diámetro interno')->assertSee('Obligatorio')->assertSee('mm')->assertDontSee('Material filtrante')
        ->set('mountedActions.0.data.categorias', [$categoria->id, $otra->id])->call('forceRender')
        ->assertSee('Diámetro interno')->assertSee('Material filtrante')->assertSee('Opcional')->assertSee('Papel · Metal')
        ->set('mountedActions.0.data.categorias', [$sinFicha->id])->call('forceRender')
        ->assertSee('Esta categoría no tiene atributos configurados.')->assertDontSee('Diámetro interno')->assertDontSee('Material filtrante')
        ->set('mountedActions.0.data.categorias', [])->call('forceRender')
        ->assertSee('Plantilla básica, sin columnas de ficha técnica.');
});

it('regenera la plantilla con atributos nuevos y detalla sus reglas por categoría', function () {
    ['categoria' => $categoria] = escenarioImportacion();
    $otra = Categoria::factory()->create(['nombre' => 'Filtros']);
    $ruta = tempnam(sys_get_temp_dir(), 'plantilla-dinamica-');
    $leer = function () use ($ruta): array {
        $reader = new Reader;
        $reader->open($ruta);
        $hojas = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $hojas[$sheet->getName()][] = $row->toArray();
                    if ($sheet->getName() === 'Productos') {
                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $hojas;
    };

    try {
        app(ArchivoProductos::class)->plantilla($ruta, 'BOB', [$categoria->id, $otra->id], ['nombre']);
        expect($leer()['Productos'][0])->toBe(['sku', 'nombre']);

        CategoriaAtributo::factory()->lista(['2RS', 'ZZ'])->create(['categoria_id' => $categoria->id, 'clave' => 'sello', 'etiqueta' => 'Sello', 'obligatorio' => true]);
        CategoriaAtributo::factory()->lista(['AB'])->create(['categoria_id' => $otra->id, 'clave' => 'sello', 'etiqueta' => 'Sello', 'obligatorio' => false]);
        $milimetros = UnidadMedida::query()->where('simbolo', 'mm')->sole();
        CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'ancho', 'etiqueta' => 'Ancho', 'unidad_medida_id' => $milimetros->id, 'obligatorio' => true]);
        app(ArchivoProductos::class)->plantilla($ruta, 'BOB', [$categoria->id, $otra->id], ['nombre']);
        $hojas = $leer();

        expect($hojas['Productos'][0])->toBe(['sku', 'nombre', 'Sello · Rodamientos, Filtros', 'Ancho [mm] · Rodamientos']);
        expect($hojas['Ficha por categoría'])->toContain(
            ['Rodamientos', 'Sello · Rodamientos, Filtros', 'Obligatorio', 'Lista', 'Sin unidad', '2RS | ZZ'],
            ['Filtros', 'Sello · Rodamientos, Filtros', 'Opcional', 'Lista', 'Sin unidad', 'AB'],
            ['Rodamientos', 'Ancho [mm] · Rodamientos', 'Obligatorio', 'Decimal', 'mm', 'Solo número; punto o coma decimal. Ejemplo: 5,5.'],
        );
        $mapa = app(MapearColumnasProductos::class)->sugerir($hojas['Productos'][0]);
        expect($mapa[2])->toStartWith('ficha_');
        expect($mapa[3])->toStartWith('ficha_');
    } finally {
        gc_collect_cycles();
        unlink($ruta);
    }
});

it('crea una marca desde el selector de la fila y la conserva seleccionada', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['marca' => 'MASAKI'])], 'marca.xlsx', null, null, false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callAction([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent('marca'),
        ], ['nombre' => 'MASAKI'])->assertHasNoErrors();
    expect(Marca::query()->where('nombre', 'MASAKI')->count())->toBe(1)
        ->and($tabla->get('mountedActions')[0]['data']['marca'])->toBe('MASAKI')
        ->and($importacion->refresh()->revision['errores'])->toBe([])
        ->and(ProductoVariante::query()->count())->toBe(0);
    $tabla->callMountedAction()->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([]);
});

it('crea la categoría completa desde el selector y solicita su ficha antes de guardar la fila', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $unidad = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['categoria' => 'Especiales'])], 'categoria.xlsx', null, null, false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->mountAction([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent('categoria'),
        ])->setActionData(['nombre' => 'Especiales'])
        ->set('mountedActions.1.data.atributos', ['nuevo' => [
            'etiqueta' => 'Diámetro', 'clave' => 'diametro', 'tipo' => 'decimal', 'obligatorio' => true, 'unidad_medida_id' => $unidad->id,
        ]])->callMountedAction()->assertHasNoErrors();
    $categoria = Categoria::query()->where('nombre', 'Especiales')->sole();
    expect($categoria->atributos->sole()->unidad_medida_id)->toBe($unidad->id)
        ->and($tabla->get('mountedActions')[0]['data']['categoria'])->toBe('Especiales');
    $tabla->setActionData(['ficha' => ['diametro' => '5']])->callMountedAction()->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and($importacion->revision['filas'][0]['variante']['atributos_json'])->toBe(['diametro' => 5]);
});

it('oculta la creación junto al selector cuando no tiene permiso de crear catálogos', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion()], 'revisar.xlsx', null, null, false, $usuario);
    $usuario->syncRoles([]);
    $usuario->givePermissionTo(['Importar:Productos', 'ViewAny:ProductoMaestro']);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id]);
    foreach (['marca', 'categoria'] as $campo) {
        $tabla->assertActionHidden([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent($campo),
        ]);
    }
});

it('retira el error de marca creada aunque falte la categoría y el guardado quede bloqueado', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $filas = [
        filaImportacion(['categoria' => 'Motor y Refrigeración', 'marca' => 'EXACT CARS']),
        filaImportacion(['fila' => 3, 'sku' => 'ABC', 'codigo_barras' => '', 'categoria' => 'Motor y Refrigeración', 'marca' => 'EXACT CARS']),
    ];
    $importacion = app(ImportarProductos::class)->preparar($filas, 'pendientes.xlsx', null, null, false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callAction([
            TestAction::make('corregirFila')->table('2'),
            TestAction::make('createOption')->schemaComponent('marca'),
        ], ['nombre' => 'EXACT CARS'])->assertHasNoErrors();
    foreach ($importacion->refresh()->revision['errores'] as $errores) {
        expect($errores)->toHaveCount(1)->and($errores[0])->toContain('categoría');
    }
    $tabla->assertDontSee('La marca «EXACT CARS»');
    $tabla->callMountedAction()->assertHasErrors(['mountedActions.0.data.categoria']);
    expect($tabla->instance()->getErrorBag()->first('mountedActions.0.data.categoria'))->toBe('Elegí una categoría activa del negocio o creala con el botón +.');
    expect($importacion->refresh()->filas)->toBe($filas)->and(ProductoVariante::query()->count())->toBe(0);
});

it('protege la revalidación de cargas ajenas y confirmadas', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'revalidar.xlsx', null, null, false, $usuario);
    $otro = TenantUser::factory()->create();
    $otro->assignRole('administrador');
    expect(fn () => $servicio->revalidar($importacion->id, $otro))->toThrow(ModelNotFoundException::class);
    Queue::fake();
    $servicio->confirmar($importacion->id, $usuario);
    expect(fn () => $servicio->revalidar($importacion->id, $usuario))->toThrow(ValidationException::class, 'confirmada o en proceso');
});

it('actualiza una revisión anterior cuando la marca ya fue creada sin volver a subir el Excel', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $filas = [filaImportacion(['marca' => 'EXACT CARS'])];
    $importacion = app(ImportarProductos::class)->preparar($filas, 'anterior.xlsx', null, null, false, $usuario);
    Marca::factory()->create(['nombre' => 'EXACT CARS']);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->callTableAction('revalidar')->assertHasNoErrors()->assertDispatched('importacion-corregida')
        ->assertDontSee('La marca «EXACT CARS»');
    expect($importacion->refresh()->revision['errores'])->toBe([])
        ->and($importacion->filas)->toBe($filas)->and(ProductoVariante::query()->count())->toBe(0);
});

it('elimina un borrador propio sin borrar catálogos y permite volver a cargar el mismo archivo', function (string $estado) {
    ['usuario' => $usuario] = escenarioImportacion();
    $marca = Marca::factory()->create(['nombre' => 'Marca conservada']);
    $filas = [filaImportacion(['marca' => $marca->nombre])];
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar($filas, 'borrador.xlsx', null, null, false, $usuario);
    $importacion->update(['estado' => $estado]);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->callTableAction('eliminarBorrador', $importacion)->assertHasNoErrors();

    expect(ImportacionProducto::query()->find($importacion->id))->toBeNull()
        ->and(Marca::query()->find($marca->id))->not->toBeNull()
        ->and(ProductoVariante::query()->count())->toBe(0)
        ->and(MovimientoInventario::query()->count())->toBe(0);
    $nueva = $servicio->preparar($filas, 'borrador.xlsx', null, null, false, $usuario);
    expect($nueva->estado)->toBe('revision')->and($nueva->id)->not->toBe($importacion->id);
})->with(['revision', 'fallida']);

it('no permite eliminar una importación ajena', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'ajena.xlsx', null, null, false, $usuario);
    $otro = TenantUser::factory()->create();
    $otro->assignRole('administrador');
    expect(fn () => $servicio->eliminarBorrador($importacion->id, $otro))->toThrow(ModelNotFoundException::class);
    expect($importacion->fresh())->not->toBeNull();
});

it('conserva las importaciones confirmadas y sus resultados al intentar eliminar el borrador', function (bool $enCola) {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    if ($enCola) {
        Queue::fake();
    }
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '25'])], 'confirmada.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $servicio->confirmar($importacion->id, $usuario);
    expect(fn () => $servicio->eliminarBorrador($importacion->id, $usuario))->toThrow(ValidationException::class, 'Solo podés eliminar un borrador');
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)->assertTableActionHidden('eliminarBorrador', $importacion);
    expect($importacion->refresh()->estado)->toBe($enCola ? 'en_cola' : 'completada')
        ->and(InventarioInicialDetalle::query()->count())->toBe($enCola ? 0 : 1)
        ->and(ProductoVariante::query()->count())->toBe($enCola ? 0 : 1);
})->with(['en cola' => true, 'completada' => false]);

it('exige al menos un lote válido para confirmar una carga de existencias iniciales', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion()], 'sin-stock.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    expect($importacion->revision['errores'])->toBe([]);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->call('verImportacion', $importacion->id)->assertSee(ImportarProductos::EXISTENCIAS_REQUERIDAS)
        ->assertDontSee('Confirmar importación')->call('confirmar')->assertHasErrors(['archivo']);
    expect($importacion->refresh()->estado)->toBe('revision')
        ->and(ProductoVariante::query()->count())->toBe(0)->and(MovimientoInventario::query()->count())->toBe(0);
});

it('admite mezclar productos con existencias y otros de solo catálogo', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([
        filaImportacion(['cantidad_inicial' => '2', 'costo_unitario' => '10']),
        filaImportacion(['fila' => 3, 'sku' => 'SIN-STOCK', 'codigo_barras' => '']),
    ], 'mixto.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    expect($importacion->revision['errores'])->toBe([])->and($importacion->revision['lotes'])->toBe(1);
    $servicio->confirmar($importacion->id, $usuario);
    expect($importacion->refresh()->estado)->toBe('completada')
        ->and(ProductoVariante::query()->count())->toBe(2)
        ->and(InventarioInicialDetalle::query()->count())->toBe(1);
});

it('bloquea en el worker una carga antigua de inventario sin existencias', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $servicio = app(ImportarProductos::class);
    $importacion = $servicio->preparar([filaImportacion()], 'antigua.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    $importacion->update(['estado' => 'en_cola']);
    $servicio->ejecutar($importacion->id);
    expect($importacion->refresh()->estado)->toBe('revision')
        ->and(ProductoVariante::query()->count())->toBe(0)->and(MovimientoInventario::query()->count())->toBe(0);
});

it('muestra los valores actuales y propuestos antes de actualizar un SKU existente', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    CategoriaAtributo::factory()->decimal()->create(['categoria_id' => $categoria->id, 'clave' => 'diametro', 'etiqueta' => 'Diámetro']);
    $servicio = app(ImportarProductos::class);
    $original = $servicio->preparar([filaImportacion(['atributos' => '{"diametro":5}'])], 'original.xlsx', null, null, false, $usuario);
    $servicio->confirmar($original->id, $usuario);
    $marca = Marca::factory()->create(['nombre' => 'Marca nueva']);
    $fila = filaImportacion(['nombre' => 'Nombre actualizado', 'marca' => $marca->nombre, 'precio_menor' => '55', 'atributos' => '{"diametro":8}']);
    $conservada = $servicio->revisar([$fila], null, false, $usuario);
    expect($conservada['filas'][0]['cambios'])->toBe([]);
    $importacion = $servicio->preparar([$fila], 'actualizacion.xlsx', null, null, true, $usuario);
    $cambios = collect($importacion->revision['filas'][0]['cambios'])->keyBy('campo');
    expect($cambios['Nombre'])->toBe(['campo' => 'Nombre', 'anterior' => 'Rodamiento 6206', 'nuevo' => 'Nombre actualizado'])
        ->and($cambios['Marca']['anterior'])->toBe('Sin especificar')
        ->and($cambios['Precio al por menor']['anterior'])->toBe('45.00')
        ->and($cambios['Precio al por menor']['nuevo'])->toBe('55.00')
        ->and($cambios)->toHaveCount(4)
        ->and(ProductoVariante::query()->sole()->precio_venta)->toBe('45.00');
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->mountAction(TestAction::make('corregirFila')->table('2'))
        ->call('forceRender')
        ->assertSee('Cambios propuestos')->assertSee('Al confirmar')->assertSee('45.00')->assertSee('55.00');
    $servicio->confirmar($importacion->id, $usuario);
    expect(ProductoVariante::query()->sole()->precio_venta)->toBe('55.00');
});

it('señala junto al selector los valores del Excel sin coincidencia y retira el aviso al corregirlos', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $marca = Marca::factory()->create(['nombre' => 'Marca registrada']);
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['categoria' => 'Categoría del Excel', 'marca' => 'Marca del Excel'])], 'valores.xlsx', null, null, false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->mountAction(TestAction::make('corregirFila')->table('2'))->call('forceRender')
        ->assertSee('Sin coincidencia')->assertSee('Del Excel: «Categoría del Excel».')
        ->assertSee('Del Excel: «Marca del Excel».');
    $tabla->setActionData(['categoria' => $categoria->nombre, 'marca' => $marca->nombre])->call('forceRender')
        ->assertDontSee('Sin coincidencia')->assertDontSee('Del Excel: «Categoría del Excel».')
        ->assertDontSee('Del Excel: «Marca del Excel».');
    expect(ProductoVariante::query()->count())->toBe(0);
});

it('reconoce nombres existentes con otras mayúsculas y permite guardar la selección canónica', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $marca = Marca::factory()->create(['nombre' => 'Marca registrada']);
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['categoria' => 'rodamientos', 'marca' => 'MARCA REGISTRADA'])], 'mayusculas.xlsx', null, null, false, $usuario);
    $tabla = Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->mountAction(TestAction::make('corregirFila')->table('2'))->call('forceRender')->assertDontSee('Sin coincidencia');
    expect($tabla->get('mountedActions')[0]['data']['categoria'])->toBe('Rodamientos')
        ->and($tabla->get('mountedActions')[0]['data']['marca'])->toBe($marca->nombre);
    $tabla->callMountedAction()->assertHasNoErrors();
    expect($importacion->refresh()->revision['errores'])->toBe([]);
});

it('distingue campos requeridos vacíos de la marca opcional sin especificar', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['categoria' => '', 'unidad_stock' => '', 'marca' => ''])], 'vacios.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->mountAction(TestAction::make('corregirFila')->table('2'))->call('forceRender')
        ->assertSee('Falta seleccionar')->assertSee('Elegí una categoría del negocio para este producto.')
        ->assertSee('Elegí la unidad con la que se contará el stock.')->assertDontSee('Sin coincidencia');
});

it('explica que falta el archivo sin mostrar claves de validación', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)->call('revisar')
        ->assertHasErrors(['data.archivo'])->assertSee('Seleccioná un archivo Excel (.xlsx) antes de revisar.')
        ->assertDontSee('validation.required');
    expect(ImportacionProducto::query()->count())->toBe(0);
});

it('explica el almacén y la fecha faltantes al cargar existencias', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => excelImportacion([filaImportacion()]), 'inventario' => true, 'almacen_id' => null, 'fecha_inventario' => null])
        ->call('revisar')->assertHasErrors(['data.almacen_id', 'data.fecha_inventario'])
        ->assertSee('Elegí el almacén para cargar las existencias iniciales.')
        ->assertSee('Elegí la fecha de las existencias iniciales.')->assertDontSee('validation.required');
    expect(ImportacionProducto::query()->count())->toBe(0);
});

it('pide relacionar u omitir una columna desconocida con un mensaje legible', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    $archivo = excelImportacion([['sku' => 'ABC', 'nota' => 'Información propia']], ['sku', 'nota']);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => $archivo])->call('revisar')->call('relacionarColumnas')
        ->assertHasErrors(['columnasData.mapa.1'])
        ->assertSee('Relacioná la columna «nota» con un campo del sistema o elegí No importar esta columna.')
        ->assertDontSee('validation.required');
    expect(ImportacionProducto::query()->count())->toBe(0);
});

it('explica los encabezados repetidos del Excel sin crear un borrador', function () {
    ['usuario' => $usuario] = escenarioImportacion();
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => excelImportacion([], ['sku', 'sku'])])->call('revisar')
        ->assertHasErrors(['archivo'])->assertSee('La primera fila debe tener hasta 100 encabezados distintos, sin columnas vacías.');
    expect(ImportacionProducto::query()->count())->toBe(0);
});

it('no pide agregar cantidades cuando el lote está pendiente por un catálogo incorrecto', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion([
        'marca' => 'Pendiente de crear', 'cantidad_inicial' => '10', 'costo_unitario' => '20',
    ])], 'catalogo-pendiente.xlsx', $almacen->id, today()->format('Y-m-d'), false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)->call('verImportacion', $importacion->id)
        ->assertSee('Corregí las filas pendientes para validar las existencias iniciales.')
        ->assertDontSee(ImportarProductos::EXISTENCIAS_REQUERIDAS)->assertDontSee('Confirmar importación');
    expect(ProductoVariante::query()->count())->toBe(0);
});

it('resume los campos pendientes de la tarjeta móvil y conserva la corrección masiva', function () {
    ['usuario' => $usuario, 'categoria' => $categoria] = escenarioImportacion();
    $importacion = app(ImportarProductos::class)->preparar([filaImportacion(['categoria' => 'Sin categoría registrada', 'marca' => 'Marca del Excel'])], 'tarjetas.xlsx', null, null, false, $usuario);
    Livewire::actingAs($usuario, 'tenant')->test(RevisionImportacionProductos::class, ['importacionId' => $importacion->id])
        ->assertSee('Código 0006206 · Nuevo · Fila 2')->assertSee('Revisar categoría y marca.')
        ->callTableBulkAction('asignarCampo', ['2'], ['campo' => 'categoria', 'valor' => $categoria->nombre])
        ->assertHasNoErrors()->assertSee('Revisar marca.')->assertDontSee('Revisar categoría y marca.');
    expect(ProductoVariante::query()->count())->toBe(0);
});

it('avisa que el Excel no tiene existencias desde el mapeo y permite seguir con solo productos', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $archivo = excelImportacion([filaImportacion()], ['sku', 'nombre', 'categoria', 'unidad_stock', 'precio_menor', 'condicion']);
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => $archivo, 'inventario' => true, 'almacen_id' => $almacen->id, 'fecha_inventario' => today()->format('Y-m-d')])
        ->call('revisar')->assertSee('no encontramos cantidades y costos completos')
        ->call('relacionarSoloProductos')->assertHasNoErrors()->assertSee('Solo catálogo');
    $carga = ImportacionProducto::query()->findOrFail($pagina->get('importacionId'));
    expect($carga->almacen_id)->toBeNull();
    expect($carga->filas[0]['sku'])->toBe('0006206');
    expect(ProductoVariante::query()->exists())->toBeFalse();
});

it('no pierde los datos de existencias del Excel si intenta omitir el costo faltante', function () {
    ['usuario' => $usuario, 'almacen' => $almacen] = escenarioImportacion();
    $archivo = excelImportacion([filaImportacion(['cantidad_inicial' => '8'])], ['sku', 'nombre', 'cantidad_inicial']);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarPagina::class)
        ->fillForm(['archivo' => $archivo, 'inventario' => true, 'almacen_id' => $almacen->id, 'fecha_inventario' => today()->format('Y-m-d')])
        ->call('revisar')->call('relacionarSoloProductos')->assertHasErrors(['archivo'])
        ->assertSet('opcionesPendientes.inventario', true)->assertSet('archivoPendiente.filas.0.valores.2', '8');
    expect(ImportacionProducto::query()->exists())->toBeFalse();
});
