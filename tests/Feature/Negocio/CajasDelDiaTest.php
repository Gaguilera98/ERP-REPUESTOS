<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaDia;
use App\Caja\CerrarCajaSesion;
use App\Caja\ConsolidarCajasDelDia;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Filament\Negocio\Pages\CajasDelDia;
use App\Livewire\Negocio\Caja\SesionesDelDia;
use App\Livewire\Negocio\Caja\VentasDeSesion;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\CompletarVentaContado;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
});

afterEach(function () {
    Carbon::setTestNow();

    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

/**
 * @return array{
 *     supervisor: TenantUser,
 *     cajeroA: TenantUser,
 *     cajeroB: TenantUser,
 *     sucursal: Sucursal,
 *     almacen: Almacen,
 *     variante: ProductoVariante,
 *     ocasional: Proveedor
 * }
 */
function negocioConCajasDelDia(string $dominio = 'cajas-dia'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create(['name' => 'Supervisor']);
    $supervisor->assignRole('supervisor');

    $cajeroA = TenantUser::factory()->create(['name' => 'Ana']);
    $cajeroA->assignRole('cajero');

    $cajeroB = TenantUser::factory()->create(['name' => 'Luis']);
    $cajeroB->assignRole('cajero');

    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $almacen = Almacen::factory()->create(['nombre' => 'Estante']);
    SucursalAlmacen::query()->create([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'es_principal' => true,
    ]);

    $variante = ProductoVariante::factory()->create([
        'sku' => 'DIA-1',
        'precio_venta' => '100.00',
        'precio_costo' => '40.0000',
    ]);

    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();

    return compact('supervisor', 'cajeroA', 'cajeroB', 'sucursal', 'almacen', 'variante', 'ocasional');
}

function stockParaCajasDelDia(ProductoVariante $variante, Almacen $almacen, TenantUser $usuario, Proveedor $ocasional, string $cantidad = '5'): void
{
    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'forma_impuesto' => 'importe',
        'impuestos' => 0,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => $cantidad,
            'costo_unitario' => '40',
        ]],
    ], $usuario);

    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => $cantidad],
    ], $usuario, true);
}

/**
 * @return array<string, string>
 */
function mediosParaCajasDelDia($sesion, array $cambios = []): array
{
    $medios = [];

    foreach (app(ArqueoCajaSesion::class)->de($sesion)['por_metodo'] as $fila) {
        $medios[$fila['metodo']] = $cambios[$fila['metodo']] ?? $fila['esperado'];
    }

    return $medios;
}

it('permite al supervisor consultar sesiones y ventas del vendedor seleccionado sin ampliar el historial personal', function () {
    ['supervisor' => $supervisor, 'cajeroA' => $cajero, 'cajeroB' => $otro, 'sucursal' => $sucursal, 'almacen' => $almacen] = negocioConCajasDelDia('supervision-sesiones');
    $sesion = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'fecha_apertura' => '2026-10-01 10:00:00']);
    $ajena = CajaSesion::factory()->create(['user_id' => $otro->id, 'sucursal_id' => $sucursal->id, 'fecha_apertura' => '2026-10-01 11:00:00']);
    $otraFecha = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'fecha_apertura' => '2026-10-02 10:00:00']);
    $otraSucursal = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => Sucursal::factory()->create()->id, 'fecha_apertura' => '2026-10-01 10:00:00']);
    $venta = Venta::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id]);
    $pago = Pago::factory()->create(['caja_sesion_id' => $sesion->id]);
    PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')->test(SesionesDelDia::class, ['fecha' => '2026-10-01', 'vendedorId' => $cajero->id, 'sucursalId' => $sucursal->id])
        ->assertCanSeeTableRecords([$sesion])
        ->assertCanNotSeeTableRecords([$ajena, $otraFecha, $otraSucursal])
        ->mountAction(TestAction::make('verSesion')->table($sesion))
        ->assertActionMounted(TestAction::make('verSesion')->table($sesion));
    Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $sesion->id, 'supervision' => true])
        ->assertCanSeeTableRecords([$venta])
        ->searchTable($venta->codigo)
        ->assertCanSeeTableRecords([$venta])
        ->searchTable('CODIGO-INEXISTENTE')
        ->assertCountTableRecords(0)
        ->searchTable('')
        ->mountAction(TestAction::make('verDetalle')->table($venta))
        ->assertActionMounted(TestAction::make('verDetalle')->table($venta));
    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $ajena->id, 'supervision' => true])->assertForbidden();
    Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => '2026-10-01', 'vendedorId' => $otro->id, 'sucursalId' => $sucursal->id])->assertForbidden();
});

it('suma las sesiones de dos cajeros en el consolidado de la sucursal', function () {
    [
        'cajeroA' => $cajeroA,
        'cajeroB' => $cajeroB,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia();

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '4');

    $sesionA = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($sesionA->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($sesionA->fresh()),
    ], $cajeroA);

    $sesionB = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '20',
    ], $cajeroB);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '100']],
    ], $cajeroB);

    $consolidado = app(ConsolidarCajasDelDia::class)->de($sucursal, now()->toDateString());

    expect($consolidado['sesiones_cerradas'])->toBe(1)
        ->and($consolidado['sesiones_abiertas'])->toBe(1)
        ->and($consolidado['total_apertura'])->toBe('30.00')
        ->and($consolidado['total_cobros'])->toBe('200.00')
        ->and($consolidado['faltante_cerrado'])->toBe('0.00')
        ->and(collect($consolidado['por_metodo'])->firstWhere('metodo', MetodoPago::Efectivo->value)['esperado'])->toBe('110.00')
        ->and(collect($consolidado['por_metodo'])->firstWhere('metodo', MetodoPago::QrExterno->value)['pendiente'])->toBe('100.00')
        ->and(collect($consolidado['sesiones'])->pluck('cajero')->all())->toContain('Ana', 'Luis')
        ->and(collect($consolidado['sesiones'])->firstWhere('cajero', 'Ana')['ventas'])->not->toBeEmpty()
        ->and(collect($consolidado['sesiones'])->firstWhere('cajero', 'Ana')['monto_apertura'])->toBe('10.00');
});

it('congela el turno de ayer y deja abrir el de hoy', function () {
    Carbon::setTestNow('2026-11-01 22:00:00');

    ['cajeroA' => $cajero, 'sucursal' => $sucursal] = negocioConCajasDelDia('corte-dia');

    $ayer = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '15',
    ], $cajero);

    Carbon::setTestNow('2026-11-02 00:11:00');

    $hoy = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '20',
    ], $cajero);

    expect($ayer->fresh()->estado_sesion)->toBe(EstadoCajaSesion::PendienteArqueo)
        ->and($hoy->estado_sesion)->toBe(EstadoCajaSesion::Abierta)
        ->and($hoy->fecha_apertura->toDateString())->toBe('2026-11-02');

    Carbon::setTestNow();
});

it('agrupa el día por vendedor y abre ventas solo al elegir la sesión', function () {
    [
        'supervisor' => $supervisor,
        'cajeroA' => $cajeroA,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia('cajas-detalle');

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '4');

    $primera = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($primera->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($primera->fresh()),
    ], $cajeroA);

    app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '5',
    ], $cajeroA);

    $codigo = Venta::query()->value('codigo');

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($supervisor, 'tenant')
        ->test(CajasDelDia::class)
        ->set('data.sucursal_id', $sucursal->id)
        ->set('data.fecha', now()->toDateString())
        ->assertSee('Vendedores del día')
        ->assertSee('sesión de caja pendiente de declarar')
        ->assertSee('Cuadra')
        ->assertDontSee('calzan')
        ->assertDontSee($codigo);

    expect(collect($pagina->instance()->vendedores())->firstWhere('cajero', 'Ana')['sesiones'])->toBe(2);

    $pagina->call('verVendedor', $cajeroA->id)
        ->assertSet('vendedorId', $cajeroA->id)
        ->assertDontSee($codigo);

    Livewire::actingAs($supervisor, 'tenant')->test(SesionesDelDia::class, ['fecha' => now()->toDateString(), 'vendedorId' => $cajeroA->id, 'sucursalId' => $sucursal->id])
        ->assertCanSeeTableRecords([$primera])
        ->mountAction(TestAction::make('verSesion')->table($primera))
        ->assertActionMounted(TestAction::make('verSesion')->table($primera));

    Livewire::actingAs($supervisor, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $primera->id, 'supervision' => true])
        ->assertCanSeeTableRecords([Venta::query()->where('codigo', $codigo)->firstOrFail()]);
});

it('cierra el día y marca solo las sesiones cerradas', function () {
    [
        'supervisor' => $supervisor,
        'cajeroA' => $cajeroA,
        'cajeroB' => $cajeroB,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia('cierre-dia');

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '4');

    $conVentas = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($conVentas->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($conVentas->fresh()),
    ], $cajeroA);

    $vacia = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '5',
    ], $cajeroB);

    $anulada = app(CerrarCajaSesion::class)->cerrar($vacia, [], $cajeroB);

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CajasDelDia::class)
        ->set('data.sucursal_id', $sucursal->id)
        ->set('data.fecha', now()->toDateString())
        ->assertSee('Cerrar el día')
        ->call('cerrarDia')
        ->assertSee('Día cerrado');

    expect($conVentas->fresh()->caja_dia_id)->not->toBeNull()
        ->and($anulada->fresh()->caja_dia_id)->toBeNull()
        ->and($anulada->estado_sesion)->toBe(EstadoCajaSesion::Anulada);
});

it('no cierra el día si queda una sesión por contar', function () {
    [
        'supervisor' => $supervisor,
        'cajeroA' => $cajeroA,
        'cajeroB' => $cajeroB,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia('cierre-pendiente');

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '2');

    $cerrada = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($cerrada->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($cerrada->fresh()),
    ], $cajeroA);

    app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '5',
    ], $cajeroB);

    expect(fn () => app(CerrarCajaDia::class)->cerrar($sucursal->id, now()->toDateString(), $supervisor))
        ->toThrow(ValidationException::class);
});

it('no vuelve a cerrar un día ya firmado', function () {
    [
        'supervisor' => $supervisor,
        'cajeroA' => $cajeroA,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia('cierre-dos-veces');

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '2');

    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($sesion->fresh()),
    ], $cajeroA);

    app(CerrarCajaDia::class)->cerrar($sucursal->id, now()->toDateString(), $supervisor);

    expect(fn () => app(CerrarCajaDia::class)->cerrar($sucursal->id, now()->toDateString(), $supervisor))
        ->toThrow(ValidationException::class);
});

it('impide abrir otra sesión el día cerrado y deja abrir el día siguiente', function () {
    [
        'cajeroA' => $cajeroA,
        'supervisor' => $supervisor,
        'sucursal' => $sucursal,
        'almacen' => $almacen,
        'variante' => $variante,
        'ocasional' => $ocasional,
    ] = negocioConCajasDelDia('cierre-bloquea');

    stockParaCajasDelDia($variante, $almacen, $cajeroA, $ocasional, '2');

    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '10',
    ], $cajeroA);

    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::Efectivo->value, 'monto' => '100']],
    ], $cajeroA);

    app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => mediosParaCajasDelDia($sesion->fresh()),
    ], $cajeroA);

    app(CerrarCajaDia::class)->cerrar($sucursal->id, now()->toDateString(), $supervisor);

    expect(fn () => app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '8',
    ], $cajeroA))->toThrow(ValidationException::class);

    Carbon::setTestNow(now()->addDay()->setTime(9, 0));

    $manana = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '8',
    ], $cajeroA);

    expect($manana->estado_sesion)->toBe(EstadoCajaSesion::Abierta)
        ->and($manana->fecha_apertura->toDateString())->toBe(now()->toDateString());
});

it('deja ver Cajas del día al supervisor y se la niega al cajero', function () {
    ['supervisor' => $supervisor, 'cajeroA' => $cajero, 'sucursal' => $sucursal] = negocioConCajasDelDia('cajas-acceso');

    expect($supervisor->can('View:CajasDelDia'))->toBeTrue()
        ->and($cajero->can('View:CajasDelDia'))->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CajasDelDia::class)
        ->assertSuccessful()
        ->assertSee('vendedores del día')
        ->assertSee($sucursal->nombre);

    Livewire::actingAs($cajero, 'tenant')
        ->test(CajasDelDia::class)
        ->assertForbidden();
});
