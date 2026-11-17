<?php

use App\Filament\Negocio\Resources\Ventas\Pages\ListVentas;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Venta;
use Filament\Actions\Testing\TestAction;
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
        '--path' => 'database/migrations/tenant',
        '--realpath' => true,
        '--force' => true,
    ])->assertSuccessful();
});

afterEach(function () {
    tenancy()->end();
});

it('lista ventas sin caja abierta, permite consultar otros días y abre el detalle con la nota A4', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'historial']);

    [$vendedor, $hoy, $ayer] = $tenant->run(function (): array {
        $vendedor = TenantUser::factory()->create()->assignRole('vendedor');
        $principal = SucursalAlmacen::factory()->create(['es_principal' => true]);
        $vendedor->sucursales()->attach($principal->sucursal, ['tenant_id' => tenant()->id]);
        $ventas = Venta::factory()->for($principal->sucursal)->for($principal->almacen)->for($vendedor, 'usuario');

        return [$vendedor, $ventas->create(), $ventas->create(['created_at' => now()->subDay()])];
    });

    $this->actingAs($vendedor, 'tenant')->get('http://historial.localhost/negocio/ventas')
        ->assertOk()->assertSee($hoy->codigo)->assertDontSee($ayer->codigo);

    tenancy()->initialize($tenant);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $fecha = $ayer->created_at->toDateString();
    $detalle = TestAction::make('verDetalle')->table($ayer);

    $lista = Livewire::actingAs($vendedor, 'tenant')->test(ListVentas::class)
        ->filterTable('fecha', ['desde' => $fecha, 'hasta' => $fecha])
        ->assertCanSeeTableRecords([$ayer])
        ->assertCanNotSeeTableRecords([$hoy])
        ->mountAction($detalle)
        ->assertActionMounted($detalle);

    expect($lista->instance()->getMountedAction()->getModalContent()->render())->toContain('Imprimir nota A4');
});

it('limita el listado por autor y sucursales y permite supervisar según el permiso existente', function () {
    tenancy()->initialize(Tenant::factory()->create());
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $vendedor = TenantUser::factory()->create()->assignRole('vendedor');
    $supervisor = TenantUser::factory()->create()->assignRole('supervisor');
    $otroVendedor = TenantUser::factory()->create();
    $principal = SucursalAlmacen::factory()->create(['es_principal' => true]);
    $fueraDeAlcance = SucursalAlmacen::factory()->create();

    foreach ([$vendedor, $supervisor] as $usuario) {
        $usuario->sucursales()->attach($principal->sucursal, ['tenant_id' => tenant()->id]);
    }

    $ventas = Venta::factory()->for($principal->sucursal)->for($principal->almacen);
    $propia = $ventas->for($vendedor, 'usuario')->create();
    $deOtroVendedor = $ventas->for($otroVendedor, 'usuario')->create();
    $restringida = Venta::factory()->for($fueraDeAlcance->sucursal)->for($fueraDeAlcance->almacen)->for($vendedor, 'usuario')->create();

    Livewire::actingAs($vendedor, 'tenant')->test(ListVentas::class)
        ->assertCanSeeTableRecords([$propia])
        ->assertCanNotSeeTableRecords([$deOtroVendedor, $restringida]);

    Livewire::actingAs($supervisor, 'tenant')->test(ListVentas::class)
        ->assertCanSeeTableRecords([$propia, $deOtroVendedor])
        ->assertCanNotSeeTableRecords([$restringida])
        ->filterTable('user_id', $otroVendedor->id)
        ->assertCanSeeTableRecords([$deOtroVendedor])
        ->assertCanNotSeeTableRecords([$propia]);

    Livewire::actingAs($otroVendedor, 'tenant')->test(ListVentas::class)->assertForbidden();
});
