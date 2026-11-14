<?php

use App\Enums\UsoUnidad;
use App\Filament\Negocio\Resources\Categorias\Schemas\CategoriaForm;
use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['database.connections.pool_shared_1' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]]);

    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

it('al crear un negocio deja unidades de atributo listas para la ficha del rubro', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $opciones = CategoriaForm::opcionesDeUnidad();
    $longitud = collect($opciones['Longitud'] ?? []);

    expect(UnidadMedida::query()->where('uso', UsoUnidad::Atributo)->pluck('simbolo')->all())
        ->toEqualCanonicalizing(['mm', 'in', 'V', 'A', 'bar'])
        ->and($opciones)->toHaveKeys(['Longitud', 'Eléctrico', 'Presión', 'Masa', 'Volumen'])
        ->and($longitud->contains('Milímetro (mm)'))->toBeTrue()
        ->and($longitud->contains('Centímetro (cm)'))->toBeTrue()
        ->and($longitud->contains('Metro (m)'))->toBeTrue()
        ->and($opciones)->not->toHaveKey('Conteo')
        ->and(UnidadMedida::query()->where('simbolo', 'pza')->sole()->uso)->toBe(UsoUnidad::Cantidad);

    tenancy()->end();
});

it('carga las unidades faltantes en un negocio existente y conserva su unidad de stock', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $milimetro = UnidadMedida::query()->where('simbolo', 'mm')->sole();
    $milimetro->delete();
    $centimetro = UnidadMedida::query()->where('simbolo', 'cm')->sole();
    tenancy()->end();

    $migracion = require database_path('migrations/tenant/2026_11_14_022917_asegurar_unidades_de_atributo_en_negocios.php');
    tenancy()->initialize($tenant);
    $migracion->up();
    $migracion->up();

    expect(UnidadMedida::query()->where('simbolo', 'mm')->sole()->uso)->toBe(UsoUnidad::Atributo)
        ->and($centimetro->fresh()->getAttributes())->toBe($centimetro->getAttributes());
    tenancy()->end();
});

it('migra los datos en la conexión del pool y conserva las unidades que colisionan', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    UnidadMedida::query()->where('simbolo', 'mm')->sole()->update(['simbolo' => 'mil']);
    UnidadMedida::query()->where('simbolo', 'in')->sole()->update(['uso' => UsoUnidad::Cantidad]);
    UnidadMedida::query()->where('simbolo', 'bar')->sole()->delete();
    tenancy()->end();

    Log::spy();
    $migracion = '2026_11_14_022917_asegurar_unidades_de_atributo_en_negocios';
    DB::connection('pool_shared_1')->table('migrations')->where('migration', $migracion)->delete();

    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => 'database/migrations/tenant/'.$migracion.'.php',
        '--force' => true,
    ])->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(UnidadMedida::query()->where('simbolo', 'mil')->sole()->nombre)->toBe('Milímetro')
        ->and(UnidadMedida::query()->where('simbolo', 'mm')->exists())->toBeFalse()
        ->and(UnidadMedida::query()->where('simbolo', 'in')->sole()->uso)->toBe(UsoUnidad::Cantidad)
        ->and(UnidadMedida::query()->where('simbolo', 'bar')->sole()->uso)->toBe(UsoUnidad::Atributo);
    Log::shouldHaveReceived('warning')->twice();
    tenancy()->end();
});
