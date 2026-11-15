<?php

use App\Exceptions\Tenancy\TenantContextMismatchException;
use App\Exceptions\Tenancy\TenantContextMissingException;
use App\Exceptions\Tenancy\TenantPoolNotConfiguredException;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('migrate', [
        '--database' => 'pool_shared_1',
        '--path' => database_path('migrations/tenant'),
        '--realpath' => true,
        '--force' => true,
    ]);
});

it('points the tenant at its pool and restores the central connection afterwards', function () {
    $central = config('tenancy.database.central_connection');
    $tenant = Tenant::factory()->create();

    tenancy()->initialize($tenant);

    expect(DB::getDefaultConnection())->toBe('pool_shared_1')
        ->and(config('session.connection'))->toBe($central)
        ->and(config('cache.stores.database.connection'))->toBe($central)
        ->and(config('queue.connections.database.connection'))->toBe($central);

    tenancy()->end();

    expect(DB::getDefaultConnection())->toBe($central)
        ->and(tenancy()->initialized)->toBeFalse();
});

it('rejects a tenant whose pool is not registered', function () {
    $central = config('tenancy.database.central_connection');
    $tenant = Tenant::factory()->create(['db_pool' => 'pool_shared_2']);

    expect(fn () => tenancy()->initialize($tenant))->toThrow(TenantPoolNotConfiguredException::class);

    expect(DB::getDefaultConnection())->toBe($central)
        ->and(tenancy()->initialized)->toBeFalse();
});

it('rejects a tenant without a pool', function () {
    $tenant = Tenant::factory()->create();
    $tenant->db_pool = '';
    $tenant->save();

    expect(fn () => tenancy()->initialize($tenant))->toThrow(TenantPoolNotConfiguredException::class);

    expect(tenancy()->initialized)->toBeFalse();
});

it('returns only the active tenant when the email is shared', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();

    tenancy()->initialize($north);
    TenantUser::factory()->create([
        'email' => 'maria@taller.com',
        'name' => 'Maria Norte',
    ]);

    tenancy()->initialize($south);
    TenantUser::factory()->create([
        'email' => 'maria@taller.com',
        'name' => 'Maria Sur',
    ]);

    $found = TenantUser::query()->where('email', 'maria@taller.com')->first();

    expect($found)->not->toBeNull()
        ->and($found->name)->toBe('Maria Sur')
        ->and($found->tenant_id)->toBe($south->id);

    tenancy()->end();
});

it('refuses tenant data when no tenant is active', function () {
    expect(fn () => TenantUser::query()->get())->toThrow(TenantContextMissingException::class);
});

it('refuses to store a record for another tenant', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();

    tenancy()->initialize($north);

    expect(fn () => TenantUser::factory()->create([
        'tenant_id' => $south->id,
    ]))->toThrow(TenantContextMismatchException::class);

    tenancy()->end();
});

it('removes the deleted tenant rows and keeps the other tenant', function () {
    $north = Tenant::factory()->create();
    $south = Tenant::factory()->create();
    $northDomain = $north->domains()->create(['domain' => 'taller-norte']);
    $southDomain = $south->domains()->create(['domain' => 'taller-sur']);
    Storage::fake();
    Storage::fake('public');

    tenancy()->initialize($north);
    $northUser = TenantUser::factory()->create();

    tenancy()->initialize($south);
    $southUser = TenantUser::factory()->create();
    tenancy()->end();

    $north->delete();

    $this->assertModelMissing($north);
    $this->assertModelMissing($northDomain);
    $this->assertModelExists($south);
    $this->assertModelExists($southDomain);
    $this->assertDatabaseMissing('users', ['id' => $northUser->id], 'pool_shared_1');
    $this->assertDatabaseHas('users', ['id' => $southUser->id], 'pool_shared_1');
});

it('keeps the tenant and restores its rows when the pool cleanup fails', function () {
    $tenant = Tenant::factory()->create();
    $domain = $tenant->domains()->create(['domain' => 'taller-norte']);
    $user = $tenant->run(fn () => TenantUser::factory()->create());
    $pool = DB::connection('pool_shared_1');
    $pool->getSchemaBuilder()->create('deletion_blockers', function (Blueprint $table): void {
        $table->foreignId('user_id')->constrained('users');
    });
    $pool->table('deletion_blockers')->insert(['user_id' => $user->id]);
    $disk = Storage::fake('public');
    $path = 'tenants/'.$tenant->id.'/producto.jpg';
    $disk->put($path, 'imagen');

    expect(fn () => $tenant->delete())->toThrow(QueryException::class);

    $this->assertModelExists($tenant);
    $this->assertModelExists($domain);
    $this->assertDatabaseHas('users', ['id' => $user->id], 'pool_shared_1');
    $this->assertDatabaseHas('roles', ['tenant_id' => $tenant->id], 'pool_shared_1');
    $disk->assertExists($path);
});

it('includes all tenant-owned tables in the pool cleanup', function () {
    $schema = DB::connection('pool_shared_1')->getSchemaBuilder();
    $cleanupTables = config('tenant-pools.tables');

    foreach ($schema->getTableListing(schemaQualified: false) as $table) {
        if ($schema->hasColumn($table, 'tenant_id') || $table === 'role_has_permissions') {
            expect($cleanupTables)->toContain($table);
        }
    }
});

it('resolves the tenant domain on its pool and returns to the central connection', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'taller-norte']);

    $this->get('http://taller-norte.localhost/tenant/whoami')
        ->assertOk()
        ->assertJson([
            'tenant_id' => $tenant->id,
            'connection' => 'pool_shared_1',
        ]);

    expect(DB::getDefaultConnection())->toBe(config('tenancy.database.central_connection'))
        ->and(tenancy()->initialized)->toBeFalse();
});

it('returns 404 for a tenant route on a central domain', function () {
    $this->get('http://localhost/tenant/whoami')->assertNotFound();
});

it('redirects the central homepage to the administration panel', function () {
    $this->get('http://localhost/')->assertRedirect('http://localhost/admin');
});

it('returns 404 for the homepage on an unrelated host', function () {
    $this->get('http://example.test/')->assertNotFound()->assertSee('Negocio no encontrado');
});

it('redirects a registered tenant homepage to the business panel', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'taller-norte']);

    $this->get('http://taller-norte.localhost/')->assertRedirect('http://taller-norte.localhost/negocio');
    $this->get('http://taller-norte.localhost/negocio')->assertRedirect('http://taller-norte.localhost/negocio/login');
});

it('shows a business not found page on the homepage and panel of a deleted tenant', function () {
    Storage::fake();
    Storage::fake('public');
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'taller-norte']);
    $tenant->delete();

    $this->get('http://taller-norte.localhost/')->assertNotFound()->assertSee('Negocio no encontrado');
    $this->get('http://taller-norte.localhost/negocio')->assertNotFound()->assertSee('Negocio no encontrado');
    $this->getJson('http://taller-norte.localhost/tenant/whoami')
        ->assertNotFound()
        ->assertJsonPath('message', 'Negocio no encontrado');
});
