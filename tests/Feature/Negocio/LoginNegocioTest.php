<?php

use App\Filament\Negocio\Auth\Login;
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
});

it('muestra el acceso animado en el negocio y mantiene el acceso central separado', function () {
    $tenant = Tenant::factory()->create(['name' => 'Taller El Rápido']);
    $tenant->domains()->create(['domain' => 'taller']);

    $this->get('http://taller.localhost/negocio/login')
        ->assertOk()
        ->assertSee('tobora-login-gear-clip', escape: false)
        ->assertSee('Repetir animación del logo')
        ->assertSee('Ingresá a tu cuenta de Taller El Rápido.')
        ->assertSee('Entrar')
        ->assertSee('wire:submit="authenticate"', escape: false);

    $this->get('http://localhost/admin/login')
        ->assertOk()
        ->assertDontSee('tobora-login-gear-clip', escape: false);
});

it('conserva la autenticación de Filament y rechaza las credenciales de otro negocio', function () {
    $tenant = Tenant::factory()->create();
    $otroTenant = Tenant::factory()->create();
    $otroUsuario = $otroTenant->run(fn () => TenantUser::factory()->create(['password' => 'clave-de-prueba']));

    tenancy()->initialize($tenant);

    try {
        $usuario = TenantUser::factory()->create(['password' => 'clave-de-prueba']);
        Filament::setCurrentPanel(Filament::getPanel('negocio'));

        Livewire::test(Login::class)
            ->set('data.email', $otroUsuario->email)
            ->set('data.password', 'clave-de-prueba')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest('tenant');

        Livewire::test(Login::class)
            ->set('data.email', $usuario->email)
            ->set('data.password', 'clave-de-prueba')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($usuario, 'tenant');
    } finally {
        tenancy()->end();
    }
});
