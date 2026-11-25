<?php

use App\Catalogo\ArchivoConfiguracionCatalogo;
use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarConfiguracionCatalogo;
use App\Enums\UsoUnidad;
use App\Filament\Negocio\Resources\Categorias\Pages\ImportarConfiguracion;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\MovimientoInventario;
use App\Models\ProductoMaestro;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (config('database.connections.pool_shared_1.driver') !== 'sqlite' || config('database.connections.pool_shared_1.database') !== ':memory:') {
        throw new LogicException('Estas pruebas solo pueden ejecutarse con el pool SQLite en memoria.');
    }
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

function usuarioConfiguracionCatalogo(): TenantUser
{
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $usuario = TenantUser::factory()->create();
    $usuario->assignRole('administrador');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    return $usuario;
}

function datosConfiguracionCatalogo(): array
{
    return [
        'Unidades' => [['_fila' => 2, 'codigo' => 'U_TEST', 'nombre' => 'Medida técnica de prueba', 'simbolo' => 'mtp', 'uso' => 'atributo', 'magnitud' => 'longitud', 'factor_a_referencia' => '', 'decimales' => '', 'es_referencia' => '']],
        'Categorias' => [['_fila' => 2, 'codigo' => 'CAT_TEST', 'nombre' => 'Categoría técnica de prueba']],
        'Atributos' => [
            ['_fila' => 2, 'categoria' => 'CAT_TEST', 'etiqueta' => 'Diámetro', 'clave' => 'diametro', 'tipo' => 'decimal', 'unidad' => 'U_TEST', 'obligatorio' => 'si', 'opciones' => ''],
            ['_fila' => 3, 'categoria' => 'CAT_TEST', 'etiqueta' => 'Sello', 'clave' => 'sello', 'tipo' => 'lista', 'unidad' => '', 'obligatorio' => 'no', 'opciones' => '2RS|ZZ'],
        ],
    ];
}

function excelConfiguracionCatalogo(array $datos): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'test-configuracion-');
    $writer = new Writer;
    $writer->openToFile($ruta);
    try {
        foreach (ArchivoConfiguracionCatalogo::HOJAS as $nombre => $columnas) {
            $sheet = $nombre === 'Unidades' ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($nombre);
            $writer->addRow(ArchivoProductos::filaDeTexto($columnas));
            foreach ($datos[$nombre] as $fila) {
                $writer->addRow(ArchivoProductos::filaDeTexto(array_map(fn (string $columna): string => $fila[$columna], $columnas)));
            }
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('configuracion.xlsx', file_get_contents($ruta));
    } finally {
        unlink($ruta);
    }
}

it('revisa el Excel sin escribir y confirma categorías atributos y unidades juntas', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $cantidadUnidades = UnidadMedida::query()->count();
    $pagina = Livewire::actingAs($usuario, 'tenant')->test(ImportarConfiguracion::class)
        ->fillForm(['archivo' => excelConfiguracionCatalogo(datosConfiguracionCatalogo())])
        ->call('revisar')->assertHasNoErrors()->assertSee('Revisar antes de crear')->assertSee('Medida técnica de prueba');
    expect(UnidadMedida::query()->count())->toBe($cantidadUnidades);
    expect(Categoria::query()->where('nombre', 'Categoría técnica de prueba')->exists())->toBeFalse();

    $pagina->call('confirmar')->assertHasNoErrors()->assertNotified('Configuración importada');

    $categoria = Categoria::query()->where('nombre', 'Categoría técnica de prueba')->sole();
    $unidad = UnidadMedida::query()->where('simbolo', 'mtp')->sole();
    expect($unidad->uso)->toBe(UsoUnidad::Atributo);
    expect($categoria->atributos()->where('clave', 'diametro')->sole()->unidad_medida_id)->toBe($unidad->id);
    expect($categoria->atributos()->where('clave', 'sello')->sole()->opciones)->toBe(['2RS', 'ZZ']);
    expect(ProductoMaestro::query()->count())->toBe(0);
    expect(MovimientoInventario::query()->count())->toBe(0);
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $revision = $servicio->revisar(datosConfiguracionCatalogo(), [], $usuario);
    expect($servicio->confirmar(datosConfiguracionCatalogo(), [], $revision['version'], $usuario))->toBe(['unidades' => 0, 'categorias' => 0, 'atributos' => 0]);
});

it('permite relacionar una medida con una unidad existente compatible sin crear duplicados', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $milimetros = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $archivo = datosConfiguracionCatalogo();
    $revision = $servicio->revisar($archivo, ['U_TEST' => $milimetros->id], $usuario);

    expect($revision['unidades']['U_TEST']['accion'])->toBe('reutilizar');
    $resultado = $servicio->confirmar($archivo, ['U_TEST' => $milimetros->id], $revision['version'], $usuario);
    expect($resultado['unidades'])->toBe(0);
    expect(CategoriaAtributo::query()->where('clave', 'diametro')->sole()->unidad_medida_id)->toBe($milimetros->id);
});

it('no infiere equivalencia por el nombre cuando el símbolo es distinto', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $unidad = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'][0]['nombre'] = $unidad->nombre;
    $revision = app(ImportarConfiguracionCatalogo::class)->revisar($archivo, [], $usuario);

    expect($revision['unidades']['U_TEST']['accion'])->toBe('conflicto');
    expect(implode(' ', $revision['errores']))->toContain('otra definición');
});

it('bloquea unidades ajenas o con uso y magnitud incompatibles', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $pieza = UnidadMedida::query()->where('simbolo', 'pza')->sole();
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $revision = $servicio->revisar(datosConfiguracionCatalogo(), ['U_TEST' => $pieza->id], $usuario);
    expect($revision['unidades']['U_TEST']['accion'])->toBe('conflicto');
    $revision = $servicio->revisar(datosConfiguracionCatalogo(), ['U_TEST' => 999999], $usuario);
    expect($revision['unidades']['U_TEST']['accion'])->toBe('conflicto');
    expect(fn () => $servicio->confirmar(datosConfiguracionCatalogo(), ['U_TEST' => 999999], $revision['version'], $usuario))->toThrow(ValidationException::class);
    expect(CategoriaAtributo::query()->count())->toBe(0);
});

it('revalida permisos y cambios de catálogo al confirmar', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $archivo = datosConfiguracionCatalogo();
    $revision = $servicio->revisar($archivo, [], $usuario);
    UnidadMedida::factory()->create(['nombre' => 'Medida técnica de prueba', 'simbolo' => 'mtp', 'uso' => 'atributo', 'magnitud' => 'longitud']);
    expect(fn () => $servicio->confirmar($archivo, [], $revision['version'], $usuario))->toThrow(ValidationException::class, 'El catálogo cambió');
    $usuario->syncRoles([]);
    $usuario->syncPermissions(['ViewAny:Categoria', 'Create:Categoria']);
    $archivo['Unidades'][0]['nombre'] = 'Otra medida nueva';
    $archivo['Unidades'][0]['simbolo'] = 'omn';
    $revision = $servicio->revisar($archivo, [], $usuario);
    expect(implode(' ', $revision['errores']))->toContain('No tenés permiso para crear unidades');
    expect(fn () => $servicio->confirmar($archivo, [], $revision['version'], $usuario))->toThrow(ValidationException::class);
    expect(CategoriaAtributo::query()->count())->toBe(0);
});

it('preserva las categorías existentes y bloquea cambios a sus atributos', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $archivo = datosConfiguracionCatalogo();
    $categoria = Categoria::factory()->create(['nombre' => $archivo['Categorias'][0]['nombre'], 'sin_devolucion_comercial' => true]);
    $revision = $servicio->revisar($archivo, [], $usuario);
    $servicio->confirmar($archivo, [], $revision['version'], $usuario);
    expect($categoria->refresh()->sin_devolucion_comercial)->toBeTrue();
    $archivo['Atributos'][0]['obligatorio'] = 'no';
    $revision = $servicio->revisar($archivo, [], $usuario);
    expect(implode(' ', $revision['errores']))->toContain('La clave ya existe con otra definición');
    expect(fn () => $servicio->confirmar($archivo, [], $revision['version'], $usuario))->toThrow(ValidationException::class);
    expect($categoria->atributos()->where('clave', 'diametro')->sole()->obligatorio)->toBeTrue();
});

it('muestra errores con hoja y fila para definiciones incorrectas', function (string $hoja, int $indice, string $campo, string $valor, string $mensaje) {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $archivo[$hoja][$indice][$campo] = $valor;

    try {
        app(ImportarConfiguracionCatalogo::class)->revisar($archivo, [], $usuario);
    } catch (ValidationException $exception) {
        expect(implode(' ', $exception->errors()['archivo']))->toContain($hoja.', fila', $mensaje);
        expect(CategoriaAtributo::query()->count())->toBe(0);

        return;
    }
    $this->fail('Debía rechazar la definición.');
})->with([
    'magnitud inexistente' => ['Unidades', 0, 'magnitud', 'distancia', 'magnitud'],
    'tipo inexistente' => ['Atributos', 0, 'tipo', 'numero', 'tipo'],
    'categoría no declarada' => ['Atributos', 0, 'categoria', 'NO_EXISTE', 'categoria'],
    'unidad no declarada' => ['Atributos', 0, 'unidad', 'NO_EXISTE', 'unidad'],
    'texto con unidad' => ['Atributos', 0, 'tipo', 'texto', 'solo decimal'],
    'lista vacía' => ['Atributos', 1, 'opciones', '', 'solo lista'],
    'opciones repetidas' => ['Atributos', 1, 'opciones', 'ZZ|zz', 'únicas'],
    'opciones en decimal' => ['Atributos', 0, 'opciones', '1|2', 'solo lista'],
    'factor en atributo' => ['Unidades', 0, 'factor_a_referencia', '1000', 'dejan vacíos'],
    'clave con unidad' => ['Atributos', 0, 'clave', 'Diámetro mm', 'clave'],
]);

it('bloquea códigos repetidos y cantidades con factores incompletos', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'][] = $archivo['Unidades'][0];
    expect(fn () => app(ImportarConfiguracionCatalogo::class)->revisar($archivo, [], $usuario))->toThrow(ValidationException::class, 'no repitas');
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'][0]['uso'] = 'cantidad';
    expect(fn () => app(ImportarConfiguracionCatalogo::class)->revisar($archivo, [], $usuario))->toThrow(ValidationException::class, 'factor a referencia');
});

it('crea una unidad de cantidad con su factor y no permite reemplazar su equivalencia', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'][0] = array_replace($archivo['Unidades'][0], ['uso' => 'cantidad', 'factor_a_referencia' => '0,125', 'decimales' => '3', 'es_referencia' => 'no']);
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $revision = $servicio->revisar($archivo, [], $usuario);
    expect($revision['errores'])->toBe([]);
    $servicio->confirmar($archivo, [], $revision['version'], $usuario);
    $unidad = UnidadMedida::query()->where('simbolo', 'mtp')->sole();
    expect($unidad->factor_a_referencia)->toBe('0.125000');
    expect($unidad->decimales)->toBe(3);
    $archivo['Unidades'][0]['factor_a_referencia'] = '1';
    $revision = $servicio->revisar($archivo, ['U_TEST' => $unidad->id], $usuario);
    expect($revision['unidades']['U_TEST']['accion'])->toBe('conflicto');
    expect($unidad->refresh()->factor_a_referencia)->toBe('0.125000');
});

it('rechaza referencias con factor distinto de uno y conteo de stock como unidad de ficha', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'][0] = array_replace($archivo['Unidades'][0], ['uso' => 'cantidad', 'factor_a_referencia' => '2', 'decimales' => '0', 'es_referencia' => 'si']);
    $servicio = app(ImportarConfiguracionCatalogo::class);
    expect(fn () => $servicio->revisar($archivo, [], $usuario))->toThrow(ValidationException::class, 'factor 1');
    $archivo['Unidades'][0]['factor_a_referencia'] = '1';
    $archivo['Unidades'][0]['magnitud'] = 'conteo';
    expect(fn () => $servicio->revisar($archivo, [], $usuario))->toThrow(ValidationException::class, 'conteo de stock');
});

it('no permite relacionar una unidad real de otro tenant', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $tenantOriginal = tenant();
    tenancy()->end();
    $otroTenant = Tenant::factory()->create();
    tenancy()->initialize($otroTenant);
    $unidadAjena = UnidadMedida::factory()->create(['uso' => 'atributo', 'magnitud' => 'longitud']);
    tenancy()->end();
    tenancy()->initialize($tenantOriginal);

    $revision = app(ImportarConfiguracionCatalogo::class)->revisar(datosConfiguracionCatalogo(), ['U_TEST' => $unidadAjena->id], $usuario);
    expect($revision['unidades']['U_TEST']['accion'])->toBe('conflicto');
    expect($revision['unidades']['U_TEST']['id'])->toBeNull();
});

it('exige permiso de edición para agregar atributos a una categoría existente', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    Categoria::factory()->create(['nombre' => $archivo['Categorias'][0]['nombre']]);
    $usuario->syncRoles([]);
    $usuario->syncPermissions(['ViewAny:Categoria', 'Create:Categoria', 'Create:UnidadMedida']);

    $revision = app(ImportarConfiguracionCatalogo::class)->revisar($archivo, [], $usuario);
    expect(implode(' ', $revision['errores']))->toContain('No tenés permiso para agregar atributos');
    expect(CategoriaAtributo::query()->count())->toBe(0);
});

it('revierte unidades y categorías si falla la creación de un atributo', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $servicio = app(ImportarConfiguracionCatalogo::class);
    $revision = $servicio->revisar($archivo, [], $usuario);
    CategoriaAtributo::query()->count();
    $dispatcher = CategoriaAtributo::getEventDispatcher();
    CategoriaAtributo::setEventDispatcher(clone $dispatcher);
    try {
        CategoriaAtributo::creating(function (CategoriaAtributo $atributo): void {
            if ($atributo->clave === 'sello') {
                throw new RuntimeException('Fallo simulado del atributo.');
            }
        });
        expect(fn () => $servicio->confirmar($archivo, [], $revision['version'], $usuario))->toThrow(RuntimeException::class, 'Fallo simulado');
    } finally {
        CategoriaAtributo::setEventDispatcher($dispatcher);
    }
    expect(UnidadMedida::query()->where('simbolo', 'mtp')->exists())->toBeFalse();
    expect(Categoria::query()->where('nombre', 'Categoría técnica de prueba')->exists())->toBeFalse();
    expect(CategoriaAtributo::query()->count())->toBe(0);
});

it('descarga una plantilla con guía para IA y catálogo real de unidades', function () {
    $usuario = usuarioConfiguracionCatalogo();
    Livewire::actingAs($usuario, 'tenant')->test(ImportarConfiguracion::class)->call('descargarPlantilla')->assertFileDownloaded('plantilla-categorias-unidades.xlsx');
    $ruta = tempnam(sys_get_temp_dir(), 'plantilla-config-test-');
    try {
        app(ArchivoConfiguracionCatalogo::class)->plantilla($ruta);
        $reader = new Reader;
        $reader->open($ruta);
        $hojas = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $hojas[] = $sheet->getName();
        }
        $reader->close();
        expect($hojas)->toBe(['Unidades', 'Categorias', 'Atributos', 'Guia para usuario e IA', 'Unidades existentes']);
    } finally {
        unset($reader, $sheet);
        gc_collect_cycles();
        unlink($ruta);
    }
});

it('rechaza acceso sin permiso de creación de categorías', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $usuario->syncRoles([]);
    $usuario->syncPermissions(['ViewAny:Categoria']);
    Livewire::actingAs($usuario, 'tenant')->test(ImportarConfiguracion::class)->assertForbidden();
});

it('admite categorías sin unidades ni atributos y exige archivo para revisar', function () {
    $usuario = usuarioConfiguracionCatalogo();
    $archivo = datosConfiguracionCatalogo();
    $archivo['Unidades'] = [];
    $archivo['Atributos'] = [];
    Livewire::actingAs($usuario, 'tenant')->test(ImportarConfiguracion::class)
        ->call('revisar')->assertHasErrors(['data.archivo'])
        ->fillForm(['archivo' => excelConfiguracionCatalogo($archivo)])
        ->call('revisar')->assertHasNoErrors()->assertSee('Sin atributos en este archivo.')
        ->call('confirmar')->assertHasNoErrors()->assertNotified('Configuración importada');
    expect(Categoria::query()->where('nombre', 'Categoría técnica de prueba')->exists())->toBeTrue();
});

it('rechaza hojas ausentes encabezados inválidos y fórmulas antes de revisar', function (string $caso, string $mensaje) {
    usuarioConfiguracionCatalogo();
    $ruta = tempnam(sys_get_temp_dir(), 'config-invalida-');
    $writer = new Writer;
    $writer->openToFile($ruta);
    try {
        foreach (ArchivoConfiguracionCatalogo::HOJAS as $nombre => $columnas) {
            if ($caso === 'hoja ausente' && $nombre === 'Atributos') {
                continue;
            }
            $sheet = $nombre === 'Unidades' ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($nombre);
            $writer->addRow(ArchivoProductos::filaDeTexto($caso === 'encabezado' && $nombre === 'Categorias' ? ['nombre'] : $columnas));
            if ($nombre === 'Categorias') {
                $writer->addRow(Row::fromValues([$caso === 'fórmula' ? '=1+1' : 'CAT_TEST', 'Prueba']));
            }
        }
        $writer->close();
        expect(fn () => app(ArchivoConfiguracionCatalogo::class)->leer($ruta))->toThrow(ValidationException::class, $mensaje);
        expect(Categoria::query()->where('nombre', 'Prueba')->exists())->toBeFalse();
    } finally {
        unlink($ruta);
    }
})->with([
    'hoja faltante' => ['hoja ausente', 'Incluí Unidades, Categorias y Atributos'],
    'encabezado incorrecto' => ['encabezado', 'encabezados de Categorias'],
    'fórmula' => ['fórmula', 'pegá valores en lugar de fórmulas'],
]);
