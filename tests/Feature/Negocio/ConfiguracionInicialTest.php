<?php

use App\Filament\Negocio\Pages\ConfiguracionInicial;
use App\Filament\Negocio\Pages\Dashboard;
use App\Models\Almacen;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

    Filament::setCurrentPanel(Filament::getPanel('negocio'));
});

it('redirige desde el inicio y permite crear y vincular la primera sucursal y su almacén', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'inicio']);
    tenancy()->initialize($tenant);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('administrador');
    tenancy()->end();

    $this->actingAs($supervisor, 'tenant')->get('http://inicio.localhost/negocio')
        ->assertRedirect('http://inicio.localhost/negocio/configuracion-inicial');

    tenancy()->initialize($tenant);
    Livewire::actingAs($supervisor, 'tenant')->test(ConfiguracionInicial::class)
        ->assertSee('Guardar y continuar')
        ->set('data.nombre_sucursal', '')
        ->call('guardar')->assertHasErrors(['data.nombre_sucursal' => 'required'])
        ->set('data.nombre_sucursal', 'Local Centro')
        ->call('guardar')->assertHasNoErrors()->assertRedirect(Dashboard::getUrl());

    $sucursal = Sucursal::query()->sole();
    $almacen = Almacen::query()->sole();
    $asignacion = $sucursal->asignaciones()->sole();
    expect($sucursal->nombre)->toBe('Local Centro')
        ->and($sucursal->is_active)->toBeTrue()
        ->and($almacen->nombre)->toBe('Almacén principal')
        ->and($asignacion->almacen->is($almacen))->toBeTrue()
        ->and($asignacion->es_principal)->toBeTrue();

    Livewire::test(Dashboard::class)->assertNoRedirect();
    Livewire::test(ConfiguracionInicial::class)->assertRedirect(Dashboard::getUrl());
    tenancy()->end();
});

it('reutiliza los registros del negocio y rechaza un almacén de otro tenant', function () {
    $tenant = Tenant::factory()->create();
    $otroTenant = Tenant::factory()->create();
    tenancy()->initialize($otroTenant);
    $almacenAjeno = Almacen::factory()->create();
    tenancy()->initialize($tenant);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('administrador');
    $sucursal = Sucursal::factory()->create();
    $almacen = Almacen::factory()->create();
    $asignacion = SucursalAlmacen::factory()->for($sucursal)->for($almacen)->create();

    Livewire::actingAs($supervisor, 'tenant')->test(ConfiguracionInicial::class)
        ->set('data.almacen', $almacenAjeno->id)
        ->call('guardar')->assertHasErrors(['data.almacen'])
        ->set('data.almacen', $almacen->id)
        ->call('guardar')->assertHasNoErrors()->assertRedirect(Dashboard::getUrl());

    expect(Sucursal::query()->count())->toBe(1)
        ->and(Almacen::query()->count())->toBe(1)
        ->and($sucursal->asignaciones()->sole()->is($asignacion))->toBeTrue()
        ->and($asignacion->refresh()->es_principal)->toBeTrue();

    tenancy()->end();
});

it('muestra la situación al cajero y rechaza el guardado sin permisos', function () {
    $tenant = Tenant::factory()->create();
    tenancy()->initialize($tenant);
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');

    Livewire::actingAs($cajero, 'tenant')->test(ConfiguracionInicial::class)
        ->assertSee('Pedile a la persona encargada')
        ->assertDontSee('Guardar y continuar')
        ->call('guardar')->assertForbidden();

    expect(Sucursal::query()->exists())->toBeFalse()
        ->and(Almacen::query()->exists())->toBeFalse();
    tenancy()->end();
});
