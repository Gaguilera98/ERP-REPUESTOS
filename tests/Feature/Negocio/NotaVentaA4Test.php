<?php

use App\Enums\CondicionVenta;
use App\Enums\EstadoVenta;
use App\Enums\MetodoPago;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('imprime el precio de la venta, el vuelto de contado y el saldo de crédito en A4', function () {
    $tenant = Tenant::factory()->create(['name' => 'Taller El Rápido']);
    $tenant->domains()->create(['domain' => 'taller']);

    [$usuario, $contado, $credito] = $tenant->run(function (): array {
        $usuario = TenantUser::factory()->create()->assignRole('administrador');
        $asignacion = SucursalAlmacen::factory()->create(['es_principal' => true]);
        $ventas = Venta::factory()->for($asignacion->sucursal)->for($asignacion->almacen)->for($usuario, 'usuario');
        $contado = $ventas->create();
        $credito = $ventas->create(['condicion' => CondicionVenta::Credito]);
        $variante = ProductoVariante::factory()->create(['precio_venta' => '74.99']);

        foreach ([$contado, $credito] as $venta) {
            VentaDetalle::factory()->for($venta)->for($variante, 'variante')->create([
                'cantidad' => '2.0000',
                'cantidad_base' => '2.0000',
                'precio_unitario' => '50.00',
                'total_linea' => '100.00',
            ]);
        }

        $efectivo = Pago::factory()->create(['efectivo_recibido' => '125.00', 'cambio_entregado' => '25.00']);
        PagoAplicacion::factory()->for($contado)->for($efectivo)->create();

        $abono = Pago::factory()->create(['metodo_pago' => MetodoPago::Transferencia, 'monto' => '30.00']);
        PagoAplicacion::factory()->for($credito)->for($abono)->create(['monto' => '30.00']);

        return [$usuario, $contado, $credito];
    });

    $this->actingAs($usuario, 'tenant');

    $this->get('http://taller.localhost/negocio/ventas/'.$contado->id.'/nota-a4')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('size: A4 portrait')
        ->assertSee('Taller El Rápido')
        ->assertSee($contado->codigo)
        ->assertSee('BOB 50.00')
        ->assertDontSee('BOB 74.99')
        ->assertSeeInOrder(['Efectivo recibido', 'BOB 125.00', 'Vuelto entregado', 'BOB 25.00'])
        ->assertSee('NO TIENE VALIDEZ FISCAL');

    $this->get('http://taller.localhost/negocio/ventas/'.$credito->id.'/nota-a4')
        ->assertOk()
        ->assertSeeInOrder(['Pagado a la fecha', 'BOB 30.00', 'Saldo pendiente', 'BOB 70.00'])
        ->assertDontSee('Vuelto entregado');
});

it('protege la nota por sesión, sucursal y tenant y excluye reservas sin entregar', function () {
    $tenant = Tenant::factory()->create();
    $otro = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => 'taller']);
    $ventaAjena = $otro->run(function (): Venta {
        $asignacion = SucursalAlmacen::factory()->create();

        return Venta::factory()->for($asignacion->sucursal)->for($asignacion->almacen)->create();
    });

    [$usuario, $local, $restringida, $reserva] = $tenant->run(function (): array {
        $usuario = TenantUser::factory()->create()->assignRole('vendedor');
        $asignacion = SucursalAlmacen::factory()->create(['es_principal' => true]);
        $usuario->sucursales()->attach($asignacion->sucursal, ['tenant_id' => tenant()->id]);
        $ventas = Venta::factory()->for($asignacion->sucursal)->for($asignacion->almacen);
        $otraAsignacion = SucursalAlmacen::factory()->create();

        return [
            $usuario,
            $ventas->create(),
            Venta::factory()->for($otraAsignacion->sucursal)->for($otraAsignacion->almacen)->create(),
            $ventas->create(['estado' => EstadoVenta::Reserva, 'vence_en' => now()->addDay(), 'entregada_en' => null]),
        ];
    });

    $url = fn (Venta $venta): string => 'http://taller.localhost/negocio/ventas/'.$venta->id.'/nota-a4';

    $this->get($url($local))->assertRedirect('http://taller.localhost/negocio/login');
    $this->actingAs($usuario, 'tenant')->get($url($local))->assertOk();
    $this->get($url($restringida))->assertForbidden();
    $this->get($url($ventaAjena))->assertNotFound();
    $this->get($url($reserva))->assertNotFound();
});
