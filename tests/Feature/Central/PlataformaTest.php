<?php

use App\Actions\CreateNegocio;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Http\Middleware\SetPlatformPermissionTeam;
use App\Models\NegocioRole;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Rules\NegocioDomainRule;
use App\Tenancy\PlatformTeam;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    setPermissionsTeamId(PlatformTeam::Id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps platform roles out of a store and store roles out of the platform', function () {
    $tenant = Tenant::factory()->create();

    expect(NegocioRole::query()->pluck('name')->all())->toBe(['admin']);

    tenancy()->initialize($tenant);

    expect(NegocioRole::query()->pluck('name')->sort()->values()->all())->toBe(['administrador', 'cajero', 'supervisor']);

    tenancy()->end();

    expect(NegocioRole::query()->pluck('name')->all())->toBe(['admin']);
});

it('keeps the platform user on the central database while a store is open', function () {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    expect(User::query()->where('email', $admin->email)->exists())->toBeTrue()
        ->and(TenantUser::query()->where('email', $admin->email)->exists())->toBeFalse();

    tenancy()->end();
});

it('grants store management only to the platform admin role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $plain = User::factory()->create();
    $other = User::factory()->create();

    expect($admin->can('viewAny', Tenant::class))->toBeTrue()
        ->and($admin->can('create', User::class))->toBeTrue()
        ->and($admin->can('viewAny', NegocioRole::class))->toBeTrue()
        ->and($admin->can('delete', $other))->toBeTrue()
        ->and($admin->can('delete', $admin))->toBeFalse()
        ->and($plain->can('viewAny', Tenant::class))->toBeFalse()
        ->and($plain->can('viewAny', User::class))->toBeFalse()
        ->and($plain->can('viewAny', NegocioRole::class))->toBeFalse();
});

it('arma el host completo del negocio con el dominio central', function () {
    config(['tenancy.central_domains' => ['app.taboraparts.online']]);

    expect(NegocioDomainRule::centralHost())->toBe('app.taboraparts.online')
        ->and(NegocioDomainRule::hostCompleto('Prueba'))->toBe('prueba.app.taboraparts.online')
        ->and(NegocioDomainRule::hostCompleto(''))->toBeNull();
});

it('creates a store with a lowercase domain and its first administrator', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'God',
            'domain' => 'God',
            'db_pool' => 'pool_shared_1',
            'is_active' => true,
            'supervisor_name' => 'Dueño',
            'supervisor_email' => 'dueno@god.test',
            'supervisor_password' => 'secret-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('domains', [
        'domain' => 'god',
    ]);

    $tenant = Tenant::query()->where('name', 'God')->first();

    expect($tenant)->not->toBeNull()
        ->and(getPermissionsTeamId())->toBe(PlatformTeam::Id);

    tenancy()->initialize($tenant);

    $supervisor = TenantUser::query()->where('email', 'dueno@god.test')->first();

    expect($supervisor)->not->toBeNull()
        ->and($supervisor->hasRole('administrador'))->toBeTrue();

    tenancy()->end();
});

it('still allows creating a store after the platform team middleware finishes its own pipeline', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    setPermissionsTeamId(null);

    $request = Request::create('/admin/negocios/create', 'GET');

    app(SetPlatformPermissionTeam::class)->handle($request, fn () => response('ok'));

    expect(getPermissionsTeamId())->toBe(PlatformTeam::Id)
        ->and($admin->can('create', Tenant::class))->toBeTrue();
});

it('rejects a domain with characters other than letters, numbers and hyphens', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Raro',
            'domain' => 'god_local',
            'db_pool' => 'pool_shared_1',
            'supervisor_name' => 'Dueño',
            'supervisor_email' => 'dueno@raro.test',
            'supervisor_password' => 'secret-password',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'domain' => 'El subdominio solo puede usar letras minúsculas, números y guiones.',
        ]);
});

it('rejects a reserved domain when creating a store', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Reservado',
            'domain' => 'admin',
            'db_pool' => 'pool_shared_1',
            'supervisor_name' => 'Dueño',
            'supervisor_email' => 'dueno@reservado.test',
            'supervisor_password' => 'secret-password',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'domain' => 'Ese subdominio está reservado.',
        ]);

    $this->assertDatabaseMissing('tenants', [
        'name' => 'Reservado',
    ]);
});

it('rejects a domain that another store already uses', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    app(CreateNegocio::class)->create([
        'name' => 'Primero',
        'domain' => 'god',
        'db_pool' => 'pool_shared_1',
        'supervisor_name' => 'Dueño',
        'supervisor_email' => 'dueno@primero.test',
        'supervisor_password' => 'secret-password',
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Segundo',
            'domain' => 'GOD',
            'db_pool' => 'pool_shared_1',
            'supervisor_name' => 'Otra',
            'supervisor_email' => 'otra@segundo.test',
            'supervisor_password' => 'secret-password',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'domain' => 'Ese subdominio ya pertenece a otro negocio.',
        ]);
});

it('rejects a pool that is not registered', function () {
    $create = fn () => app(CreateNegocio::class)->create([
        'name' => 'Huérfano',
        'domain' => 'huerfano',
        'db_pool' => 'pool_missing',
        'supervisor_name' => 'Dueño',
        'supervisor_email' => 'dueno@huerfano.test',
        'supervisor_password' => 'secret-password',
    ]);

    expect($create)->toThrow(ValidationException::class);

    try {
        $create();
    } catch (ValidationException $exception) {
        expect($exception->errors()['db_pool'][0] ?? null)->toBe('Esa base no está registrada.');
    }

    $this->assertDatabaseMissing('tenants', [
        'name' => 'Huérfano',
    ]);
});

it('rejects an administrator password shorter than 8 characters', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin);

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Corto',
            'domain' => 'corto',
            'db_pool' => 'pool_shared_1',
            'supervisor_name' => 'Dueño',
            'supervisor_email' => 'dueno@corto.test',
            'supervisor_password' => 'corta',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'supervisor_password' => 'La contraseña del administrador debe tener al menos 8 caracteres.',
        ]);
});

it('updates the store domain slug', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $tenant = app(CreateNegocio::class)->create([
        'name' => 'God',
        'domain' => 'god',
        'db_pool' => 'pool_shared_1',
        'supervisor_name' => 'Dueño',
        'supervisor_email' => 'dueno@god.test',
        'supervisor_password' => 'secret-password',
    ]);

    $this->actingAs($admin);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm([
            'name' => 'God',
            'domain' => 'Dios',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->domains()->value('domain'))->toBe('dios');
});

it('sends a guest to the platform login', function () {
    $this->get('http://localhost/admin/negocios')
        ->assertRedirect('http://localhost/admin/login');
});

it('forbids a platform user without the admin role from opening stores', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('http://localhost/admin/negocios')
        ->assertForbidden();
});

it('lets the platform admin open stores', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('http://localhost/admin/negocios')
        ->assertOk();
});

it('keeps an inactive platform user out of the panel', function () {
    $admin = User::factory()->create(['is_active' => false]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('http://localhost/admin')
        ->assertForbidden();
});
