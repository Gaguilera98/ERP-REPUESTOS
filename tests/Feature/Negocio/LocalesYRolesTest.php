<?php

use App\Filament\Negocio\Resources\Almacens\Pages\ListAlmacens;
use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\NegocioRole;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\ModoMoneda;
use App\Moneda;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

it('seeds the store roles and the default configuration', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $configuracion = NegocioConfiguracion::query()->first();

    expect(NegocioRole::query()->pluck('name')->sort()->values()->all())->toBe(['cajero', 'supervisor'])
        ->and(Schema::connection('pool_shared_1')->hasColumn('users', 'rol'))->toBeFalse()
        ->and(Schema::connection('pool_shared_1')->hasColumn('almacenes', 'sucursal_id'))->toBeFalse()
        ->and($configuracion)->not->toBeNull()
        ->and($configuracion->moneda)->toBe(Moneda::Bob)
        ->and($configuracion->modo_moneda)->toBe(ModoMoneda::SoloMoneda)
        ->and($configuracion->horas_reserva)->toBe(48)
        ->and($configuracion->descuento_max_porcentaje)->toBe('10.00')
        ->and($configuracion->descuento_max_importe)->toBe('0.00')
        ->and($configuracion->siguiente_numero_venta)->toBe(1);

    tenancy()->end();
});

it('keeps roles and branch names inside the store', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();

    tenancy()->initialize($north);
    $northRole = NegocioRole::query()->where('name', 'cajero')->first();
    Sucursal::factory()->create(['nombre' => 'Central']);

    tenancy()->initialize($south);
    $southRole = NegocioRole::query()->where('name', 'cajero')->first();
    Sucursal::factory()->create(['nombre' => 'Central']);

    expect($northRole)->not->toBeNull()
        ->and($southRole)->not->toBeNull()
        ->and($southRole->id)->not->toBe($northRole->id)
        ->and(Sucursal::query()->count())->toBe(1);

    expect(fn () => Sucursal::factory()->create(['nombre' => 'Central']))
        ->toThrow(QueryException::class);

    tenancy()->end();
});

it('shares one warehouse between branches and keeps a single main warehouse', function () {
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    $central = Sucursal::factory()->create(['nombre' => 'Central']);
    $anillo = Sucursal::factory()->create(['nombre' => 'Anillo']);
    $deposito = Almacen::factory()->create(['nombre' => 'Depósito']);
    $estante = Almacen::factory()->create(['nombre' => 'Estante']);

    $central->asignaciones()->create([
        'almacen_id' => $deposito->id,
        'es_principal' => true,
    ]);
    $anillo->asignaciones()->create([
        'almacen_id' => $deposito->id,
        'es_principal' => true,
    ]);
    $central->asignaciones()->create([
        'almacen_id' => $estante->id,
        'es_principal' => true,
    ]);

    expect(SucursalAlmacen::query()->where('almacen_id', $deposito->id)->count())->toBe(2)
        ->and($central->asignaciones()->where('es_principal', true)->count())->toBe(1)
        ->and($central->asignaciones()->where('almacen_id', $estante->id)->first()?->es_principal)->toBeTrue()
        ->and($central->asignaciones()->where('almacen_id', $deposito->id)->first()?->es_principal)->toBeFalse();

    expect(fn () => $central->asignaciones()->create([
        'almacen_id' => $deposito->id,
        'es_principal' => false,
    ]))->toThrow(QueryException::class);

    tenancy()->end();
});

it('refuses a warehouse that belongs to another store', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();

    tenancy()->initialize($north);
    $almacen = Almacen::factory()->create();

    tenancy()->initialize($south);
    $sucursal = Sucursal::factory()->create();

    expect(fn () => SucursalAlmacen::query()->create([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
    ]))->toThrow(QueryException::class);

    tenancy()->end();
});

it('removes the deleted store and keeps the shared permission catalog', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();

    tenancy()->initialize($north);
    $sucursal = Sucursal::factory()->create();
    tenancy()->end();

    $north->delete();

    $this->assertDatabaseMissing('roles', ['tenant_id' => $north->id], 'pool_shared_1');
    $this->assertDatabaseMissing('negocio_configuracion', ['tenant_id' => $north->id], 'pool_shared_1');
    $this->assertDatabaseMissing('sucursales', ['id' => $sucursal->id], 'pool_shared_1');
    $this->assertDatabaseHas('roles', ['tenant_id' => $south->id, 'name' => 'supervisor'], 'pool_shared_1');
    $this->assertDatabaseHas('permissions', [
        'name' => 'ViewAny:NegocioRole',
        'guard_name' => 'tenant',
    ], 'pool_shared_1');
});

it('lets the supervisor manage roles and keeps the cashier out', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();
    $north->domains()->create(['domain' => 'norte']);
    $south->domains()->create(['domain' => 'sur']);

    tenancy()->initialize($north);
    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    expect($cajero->can('viewAny', NegocioRole::class))->toBeFalse()
        ->and($cajero->can('create', Sucursal::class))->toBeFalse()
        ->and($cajero->can('viewAny', Sucursal::class))->toBeTrue()
        ->and($supervisor->can('viewAny', NegocioRole::class))->toBeTrue()
        ->and($supervisor->can('update', NegocioConfiguracion::query()->first()))->toBeTrue();

    tenancy()->initialize($south);

    expect(TenantUser::query()->find($cajero->id))->toBeNull();

    tenancy()->end();

    $this->actingAs($cajero, 'tenant')
        ->get('http://norte.localhost/negocio/shield/roles')
        ->assertForbidden();

    $this->actingAs($cajero, 'tenant')
        ->get('http://norte.localhost/negocio/configuracion')
        ->assertForbidden();

    $this->flushSession();

    $this->actingAs($supervisor, 'tenant')
        ->get('http://norte.localhost/negocio/shield/roles')
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->get('http://sur.localhost/negocio/shield/roles')
        ->assertRedirect();
});

it('opens a warehouse from the list instead of its own page', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'norte']);

    tenancy()->initialize($tenant);
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(ListAlmacens::class)
        ->assertActionExists('create')
        ->mountAction('create')
        ->assertActionMounted('create');

    tenancy()->end();

    $this->actingAs($supervisor, 'tenant')
        ->get('http://norte.localhost/negocio/almacenes/create')
        ->assertNotFound();
});
