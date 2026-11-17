<?php

use App\Caja\AbrirCajaSesion;
use App\Enums\MetodoPago;
use App\Filament\Negocio\Pages\CajasDelDia;
use App\Filament\Negocio\Pages\ElegirSucursal;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Filament\Negocio\Resources\ProductoMaestros\Pages\ListProductoMaestros;
use App\Filament\Negocio\Resources\TenantUsers\Pages\EditTenantUser;
use App\Inventario\TrasladarProductos;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Sucursal;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use App\Ventas\CompletarVentaContado;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['database.connections.pool_shared_1' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    $this->artisan('migrate', [
        '--database' => 'pool_shared_1', '--path' => 'database/migrations/tenant', '--force' => true,
    ])->assertSuccessful();
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
});

afterEach(function () {
    tenancy()->end();
});

it('muestra el selector de sucursal antes del menú de usuario y oculta la búsqueda global', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'acceso']);

    $admin = $tenant->run(function (): TenantUser {
        $admin = TenantUser::factory()->create()->assignRole(AccesoSucursales::ADMINISTRADOR);
        $sucursal = Sucursal::factory()->create(['is_active' => true]);
        Sucursal::factory()->create(['is_active' => true]);
        $sucursal->asignaciones()->create(['almacen_id' => Almacen::factory()->create()->id, 'es_principal' => true]);

        return $admin;
    });

    $this->actingAs($admin, 'tenant')->get('http://acceso.localhost/negocio/productos')
        ->assertOk()
        ->assertDontSee('class="fi-global-search-ctn"', escape: false)
        ->assertSeeInOrder([
            'class="fi-topbar-end"',
            'href="http://acceso.localhost/negocio/elegir-sucursal"',
            'class="fi-user-menu-trigger"',
        ], escape: false);
});

it('usa la sucursal asignada en mostrador e impide abrir caja o trasladar a un local ajeno', function () {
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'acceso']);
    tenancy()->initialize($tenant);
    $vendedor = TenantUser::factory()->create()->assignRole('vendedor');
    $local = Sucursal::factory()->create(['nombre' => 'San Pedro', 'is_active' => true]);
    $otro = Sucursal::factory()->create(['nombre' => 'Sucursal restringida', 'is_active' => true]);
    $almacen = Almacen::factory()->create(['nombre' => 'Depósito San Pedro']);
    $ajeno = Almacen::factory()->create();
    $local->asignaciones()->create(['almacen_id' => $almacen->id, 'es_principal' => true]);
    $otro->asignaciones()->create(['almacen_id' => $ajeno->id, 'es_principal' => true]);
    $vendedor->sucursales()->attach($local, ['tenant_id' => tenant()->id]);

    Livewire::actingAs($vendedor, 'tenant')->test(MostradorDelNegocio::class)
        ->assertSet('sucursalId', $local->id)->assertSet('almacenId', $almacen->id)->assertDontSee($otro->nombre);

    expect(fn () => app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $otro->id, 'monto_apertura' => 0], $vendedor))
        ->toThrow(ValidationException::class);
    expect(fn () => app(TrasladarProductos::class)->trasladar($almacen->id, $ajeno->id, [], $vendedor))
        ->toThrow(ValidationException::class);
    expect(CajaSesion::query()->count())->toBe(0);

    $variante = ProductoVariante::factory()->create(['precio_venta' => '25.00']);
    $variante->stocks()->where('almacen_id', $almacen->id)->update(['cantidad_actual' => 2]);
    $turno = app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $local->id, 'monto_apertura' => 0], $vendedor);
    $venta = app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $local->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => 1]],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '25.00']],
    ], $vendedor)['venta'];

    expect($venta->sucursal_id)->toBe($local->id)
        ->and($venta->almacen_id)->toBe($almacen->id)
        ->and($venta->caja_sesion_id)->toBe($turno->id)
        ->and($variante->stocks()->where('almacen_id', $almacen->id)->value('cantidad_actual'))->toBe('1.0000');

    tenancy()->end();
    $this->actingAs($vendedor, 'tenant')->get('http://acceso.localhost/negocio/mostrador')
        ->assertOk()->assertSee($local->nombre)->assertDontSee($otro->nombre);
});

it('permite consultar otra sucursal autorizada y bloquea el cobro en una pestaña desactualizada', function () {
    tenancy()->initialize(Tenant::factory()->create());
    $supervisor = TenantUser::factory()->create()->assignRole('supervisor');
    [$primera, $segunda, $ajena] = Sucursal::factory()->count(3)->create(['is_active' => true])->all();
    $supervisor->sucursales()->attach([$primera->id, $segunda->id], ['tenant_id' => tenant()->id]);
    Livewire::actingAs($supervisor, 'tenant')->test(ElegirSucursal::class)->call('elegir', $primera->id)->assertRedirect();
    $mostrador = Livewire::test(MostradorDelNegocio::class)->assertSet('sucursalId', $primera->id);

    $cajas = Livewire::test(CajasDelDia::class)->set('data.sucursal_id', $segunda->id)->assertStatus(200);
    expect(app(AccesoSucursales::class)->activa($supervisor))->toBe($primera->id);
    $cajas->set('data.sucursal_id', $ajena->id)->assertNotFound();

    app(AccesoSucursales::class)->seleccionar($supervisor, $segunda->id);
    $mostrador->call('completar')->assertNotified('Cambió la sucursal activa');
});

it('autoriza todas las sucursales al administrador y permite agregar permisos directos', function () {
    tenancy()->initialize(Tenant::factory()->create());
    $admin = TenantUser::factory()->create()->assignRole(AccesoSucursales::ADMINISTRADOR);
    $vendedor = TenantUser::factory()->create()->assignRole('vendedor');
    $sucursal = Sucursal::factory()->create(['is_active' => true]);
    expect(app(AccesoSucursales::class)->activa($admin))->toBe($sucursal->id);

    Livewire::actingAs($admin, 'tenant')->test(EditTenantUser::class, ['record' => $vendedor->id])
        ->callAction('permisosExtras', data: ['directos' => ['paginas' => ['View:CajasDelDia']]])->assertHasNoErrors();

    expect($vendedor->fresh()->hasDirectPermission('View:CajasDelDia'))->toBeTrue()
        ->and($vendedor->fresh()->can('Create:Venta'))->toBeTrue();

    Livewire::test(EditTenantUser::class, ['record' => $admin->id])
        ->set('data.is_active', false)->call('save')->assertHasErrors(['data.role']);
    expect($admin->fresh()->is_active)->toBeTrue();
});

it('consolida stock de almacenes compartidos una sola vez y excluye los almacenes ajenos', function () {
    tenancy()->initialize(Tenant::factory()->create());
    $supervisor = TenantUser::factory()->create()->assignRole('supervisor');
    [$primera, $segunda, $ajena] = Sucursal::factory()->count(3)->create(['is_active' => true])->all();
    $supervisor->sucursales()->attach([$primera->id, $segunda->id], ['tenant_id' => tenant()->id]);
    $compartido = Almacen::factory()->create();
    $exclusivo = Almacen::factory()->create();
    $noAutorizado = Almacen::factory()->create();
    $primera->asignaciones()->create(['almacen_id' => $compartido->id]);
    $segunda->asignaciones()->create(['almacen_id' => $compartido->id]);
    $segunda->asignaciones()->create(['almacen_id' => $exclusivo->id]);
    $ajena->asignaciones()->create(['almacen_id' => $noAutorizado->id]);
    $producto = ProductoMaestro::factory()->create();
    $variante = ProductoVariante::factory()->create(['producto_maestro_id' => $producto->id]);
    $variante->stocks()->where('almacen_id', $compartido->id)->update(['cantidad_actual' => 2]);
    $variante->stocks()->where('almacen_id', $exclusivo->id)->update(['cantidad_actual' => 3]);
    $variante->stocks()->where('almacen_id', $noAutorizado->id)->update(['cantidad_actual' => 9]);
    app(AccesoSucursales::class)->seleccionar($supervisor, $primera->id);

    Livewire::actingAs($supervisor, 'tenant')->test(ListProductoMaestros::class)
        ->assertTableColumnFormattedStateSet('stock_fisico', '2 pza', $producto)
        ->filterTable('sucursal_stock', $segunda->id)->assertTableColumnFormattedStateSet('stock_fisico', '5 pza', $producto)
        ->filterTable('sucursal_stock', null)->assertTableColumnFormattedStateSet('stock_fisico', '5 pza', $producto)
        ->filterTable('sucursal_stock', $ajena->id)->assertTableColumnFormattedStateSet('stock_fisico', '0 pza', $producto);
    expect(app(AccesoSucursales::class)->activa($supervisor))->toBe($primera->id);
});

it('impide operar a un vendedor sin sucursales desde que se crea el negocio', function () {
    tenancy()->initialize(Tenant::factory()->create());
    $vendedor = TenantUser::factory()->create()->assignRole('vendedor');
    $sucursal = Sucursal::factory()->create(['is_active' => true]);

    Livewire::actingAs($vendedor, 'tenant')->test(ElegirSucursal::class)
        ->assertSee('Pedile al administrador')->call('elegir', $sucursal->id)->assertForbidden();
    expect(fn () => app(AbrirCajaSesion::class)->abrir(['sucursal_id' => $sucursal->id, 'monto_apertura' => 0], $vendedor))
        ->toThrow(ValidationException::class);
});
