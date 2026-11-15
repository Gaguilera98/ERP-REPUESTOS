<?php

use App\Archivos\ArchivosNegocio;
use App\Enums\TipoArchivoNegocio;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);

    Storage::fake();
    Storage::fake('public');
});

it('guarda cada tipo en la carpeta del negocio y en el disco que le corresponde', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $archivos = app(ArchivosNegocio::class);

    $foto = $archivos->guardar(UploadedFile::fake()->image('pieza.jpg'), TipoArchivoNegocio::GaleriaProducto);
    $comprobante = $archivos->guardar(UploadedFile::fake()->create('pago.pdf', 20, 'application/pdf'), TipoArchivoNegocio::ComprobantePago);

    expect($foto)->toStartWith('tenants/'.$tenant->id.'/productos/galeria/')
        ->and($comprobante)->toStartWith('tenants/'.$tenant->id.'/pagos/')
        ->and($archivos->disco(TipoArchivoNegocio::GaleriaProducto))->toBe('public')
        ->and($archivos->disco(TipoArchivoNegocio::ComprobantePago))->toBeNull()
        ->and($archivos->urlTemporal($comprobante))->toBeString()
        ->and($archivos->urlTemporal('tenants/otro/pagos/ajeno.pdf'))->toBeNull()
        ->and($archivos->urlTemporal('tenants/'.$tenant->id))->toBeNull();

    Storage::disk('public')->assertExists($foto);
    Storage::assertExists($comprobante);

    tenancy()->end();
});

it('al borrar un negocio vacía solo su carpeta en los dos discos', function () {
    $norte = Tenant::factory()->create();
    $sur = Tenant::factory()->create();
    $archivos = app(ArchivosNegocio::class);

    tenancy()->initialize($norte);
    $foto = $archivos->guardar(UploadedFile::fake()->image('norte.jpg'), TipoArchivoNegocio::FotoVariante);
    $pago = $archivos->guardar(UploadedFile::fake()->create('norte.pdf', 10, 'application/pdf'), TipoArchivoNegocio::ComprobantePago);
    tenancy()->end();

    tenancy()->initialize($sur);
    $fotoSur = $archivos->guardar(UploadedFile::fake()->image('sur.jpg'), TipoArchivoNegocio::GaleriaProducto);
    $pagoSur = $archivos->guardar(UploadedFile::fake()->create('sur.pdf', 10, 'application/pdf'), TipoArchivoNegocio::ComprobanteCajaChica);
    tenancy()->end();

    $norte->delete();

    Storage::disk('public')->assertMissing($foto);
    Storage::assertMissing($pago);
    Storage::disk('public')->assertExists($fotoSur);
    Storage::assertExists($pagoSur);
});

it('conserva el negocio y sus datos cuando el disco rechaza el borrado', function () {
    $tenant = Tenant::factory()->create();
    $dominio = $tenant->domains()->create(['domain' => 'taller-norte']);
    $usuario = $tenant->run(fn () => TenantUser::factory()->create());
    $disco = Mockery::mock(FilesystemAdapter::class);
    $disco->shouldReceive('deleteDirectory')->with('tenants/'.$tenant->id)->once()->andReturnFalse();
    Storage::set('public', $disco);

    expect(fn () => $tenant->delete())->toThrow(RuntimeException::class, 'No se pudieron borrar los archivos del negocio.');

    $this->assertModelExists($tenant);
    $this->assertModelExists($dominio);
    $this->assertDatabaseHas('users', ['id' => $usuario->id], 'pool_shared_1');
});
