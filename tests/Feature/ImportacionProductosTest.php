<?php

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos;
use App\Enums\TipoMovimientoInventario;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ImportarProductos as ImportarPagina;
use App\Inventario\AjustarInventario;
use App\Jobs\ProcesarImportacionProductos;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\InventarioInicialDetalle;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use Filament\Facades\Filament;
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
        'fila' => 2, 'sku' => '0006206', 'nombre' => 'Rodamiento 6206', 'categoria' => 'Rodamientos',
        'unidad_stock' => 'pza', 'codigo_barras' => '001234567890', 'precio_menor' => '45', 'precio_mayor' => '40',
    ], $cambios);
}

function excelImportacion(array $filas): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'test-productos-');
    $writer = new Writer;
    try {
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues(ArchivoProductos::COLUMNAS));
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues(array_map(fn (string $columna) => $fila[$columna], ArchivoProductos::COLUMNAS)));
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
    'costo obligatorio' => [['cantidad_inicial' => '2'], 'costo_unitario'],
    'cantidad negativa' => [['cantidad_inicial' => '-1', 'costo_unitario' => '20'], 'cantidad_inicial'],
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
    $pagina->call('descargarErrores')->assertFileDownloaded('productos-por-corregir.xlsx');
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
