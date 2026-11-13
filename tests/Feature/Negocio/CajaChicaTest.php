<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Caja\ConsolidarCajasDelDia;
use App\CajaChica\AdjuntarComprobante;
use App\CajaChica\EntregarVale;
use App\CajaChica\FondosCajaChica;
use App\CajaChica\Permisos;
use App\CajaChica\RegistrarGasto;
use App\CajaChica\RendirVale;
use App\Compras\GuardarOrdenCompra;
use App\Compras\RecibirMercaderia;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Filament\Negocio\Pages\CajaChica;
use App\Models\Almacen;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\CajaSesionMedio;
use App\Models\CategoriaGasto;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
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
 * @return array{cajero: TenantUser, supervisor: TenantUser, sucursal: Sucursal, almacen: Almacen, variante: ProductoVariante, ocasional: Proveedor, categoria: CategoriaGasto}
 */
function negocioDeCajaChica(string $dominio = 'cajachica'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');
    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $almacen = Almacen::factory()->create(['nombre' => 'Estante']);
    SucursalAlmacen::query()->create([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'es_principal' => true,
    ]);
    $supervisor->sucursales()->attach($sucursal->id, ['tenant_id' => (string) tenant()->getTenantKey()]);

    $variante = ProductoVariante::factory()->create([
        'sku' => 'CCH-1',
        'precio_venta' => '100.00',
        'precio_costo' => '40.0000',
    ]);
    $ocasional = Proveedor::query()->where('es_ocasional', true)->firstOrFail();
    $categoria = CategoriaGasto::query()->create(['nombre' => 'Pasajes']);

    return compact('cajero', 'supervisor', 'sucursal', 'almacen', 'variante', 'ocasional', 'categoria');
}

function habilitarCajon(): void
{
    NegocioConfiguracion::query()->firstOrFail()->update(['permite_gastos_cajon_venta' => true]);
}

function sesionDe(TenantUser $cajero, Sucursal $sucursal, string $apertura = '20'): CajaSesion
{
    return app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => $apertura,
    ], $cajero);
}

/**
 * @return array<string, string>
 */
function declaracionDe(CajaSesion $sesion, array $cambios = []): array
{
    $medios = [];

    foreach (app(ArqueoCajaSesion::class)->de($sesion)['por_metodo'] as $fila) {
        $medios[$fila['metodo']] = $cambios[$fila['metodo']] ?? $fila['esperado'];
    }

    return $medios;
}

it('deja el cajón apagado y opera el fondo independiente', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica();
    $cajero->givePermissionTo(Permisos::OPERAR);
    $sesion = sesionDe($cajero, $sucursal);

    expect(fn () => app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '5',
        'concepto' => 'Pasaje',
    ], $cajero))->toThrow(ValidationException::class);

    $fondo = app(FondosCajaChica::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'responsable_id' => $cajero->id,
        'monto_apertura' => '40',
    ], $supervisor);

    $gasto = app(RegistrarGasto::class)->registrar([
        'fondo_id' => $fondo->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '12',
        'concepto' => 'Café',
    ], $cajero);

    expect($gasto->caja_sesion_id)->toBeNull()
        ->and($fondo->fresh()->saldo_actual)->toBe('28.00')
        ->and(CajaChicaMovimiento::query()->count())->toBe(1);
});

it('permite rendir desde la fila del vale y preselecciona el fondo que recibe el sobrante', function () {
    ['supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioDeCajaChica('caja-chica-pantalla');
    $fondo = app(FondosCajaChica::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'responsable_id' => $supervisor->id,
        'monto_apertura' => '100',
    ], $supervisor);
    $vale = app(EntregarVale::class)->entregar([
        'fondo_id' => $fondo->id,
        'persona_recibe' => 'Ana',
        'monto' => '50',
        'concepto' => 'Mandado de prueba',
    ], $supervisor);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pantalla = Livewire::actingAs($supervisor, 'tenant')->test(CajaChica::class)
        ->assertSee('Pendientes de rendir')
        ->mountAction(TestAction::make('rendirVale')->arguments(['valeId' => $vale->id]))
        ->assertActionDataSet(['vale_id' => $vale->id, 'destino_fondo_id' => $fondo->id])
        ->fillForm(['monto_gasto' => '35', 'monto_sobrante' => '15'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertDontSee('Pendientes de rendir')
        ->assertSee('Gastado BOB 35.00')
        ->assertSee('65.00');

    expect($fondo->fresh()->saldo_actual)->toBe('65.00');

    $sobrante = CajaChicaMovimiento::query()->where('movimiento_origen_id', $vale->id)->sole();
    $pantalla->call('verHistorial', $fondo->id)
        ->assertCanSeeTableRecords([$sobrante, $vale], inOrder: true)
        ->mountAction(TestAction::make('verValeHistorial')->table($sobrante))
        ->assertActionMounted(TestAction::make('verValeHistorial')->table($sobrante));

    $detalle = $pantalla->instance()->getMountedAction()->getModalContent()->getData();
    expect($detalle['vale']->id)->toBe($vale->id)
        ->and($detalle['sobrante']->id)->toBe($sobrante->id);
});

it('no deja gastar el cajón de otro sin sucursal ni permiso directo', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-ajena');
    habilitarCajon();
    $otro = TenantUser::factory()->create();
    $otro->assignRole('cajero');
    $otro->givePermissionTo(Permisos::AJENA);
    $sesion = sesionDe($cajero, $sucursal, '30');

    expect(fn () => app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '5',
        'concepto' => 'Taxi',
    ], $otro))->toThrow(ValidationException::class);

    $otro->sucursales()->attach($sucursal->id, ['tenant_id' => (string) tenant()->getTenantKey()]);

    $gasto = app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '5',
        'concepto' => 'Taxi',
    ], $otro);

    expect($gasto->registrado_por_user_id)->toBe($otro->id)
        ->and($gasto->caja_sesion_id)->toBe($sesion->id)
        ->and(app(ArqueoCajaSesion::class)->de($sesion->fresh())['teorico_efectivo'])->toBe('25.00');
});

it('rechaza un segundo gasto que deja el efectivo en negativo y no duplica la misma clave', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-saldo');
    habilitarCajon();
    $sesion = sesionDe($cajero, $sucursal, '20');

    app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '12',
        'concepto' => 'Primero',
        'idempotency_key' => 'gasto-1',
    ], $cajero);

    expect(fn () => app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '12',
        'concepto' => 'Segundo',
    ], $cajero))->toThrow(ValidationException::class);

    $repetido = app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '12',
        'concepto' => 'Primero otra vez',
        'idempotency_key' => 'gasto-1',
    ], $cajero);

    expect(CajaChicaMovimiento::query()->count())->toBe(1)
        ->and($repetido->concepto)->toBe('Primero')
        ->and(app(ArqueoCajaSesion::class)->de($sesion->fresh())['teorico_efectivo'])->toBe('8.00');
});

it('no usa el qr como efectivo y manda a revisión un gasto ya ocurrido que no entra', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'almacen' => $almacen, 'variante' => $variante, 'ocasional' => $ocasional, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-qr');
    habilitarCajon();

    $orden = app(GuardarOrdenCompra::class)->crear([
        'proveedor_id' => $ocasional->id,
        'almacen_destino_id' => $almacen->id,
        'lineas' => [[
            'producto_variante_id' => $variante->id,
            'unidad_medida_id' => $variante->productoMaestro->unidad_base_id,
            'cantidad_pedida' => '1',
            'costo_unitario' => '40',
        ]],
    ], $cajero);
    app(RecibirMercaderia::class)->recibir($orden, [
        ['detalle_id' => $orden->detalles->first()->id, 'cantidad' => '1'],
    ], $cajero, true);

    $sesion = sesionDe($cajero, $sucursal, '10');
    app(CompletarVentaContado::class)->completar([
        'sucursal_id' => $sucursal->id,
        'almacen_id' => $almacen->id,
        'lineas' => [['producto_variante_id' => $variante->id, 'cantidad' => '1']],
        'pagos' => [['metodo_pago' => MetodoPago::QrExterno->value, 'monto' => '100']],
    ], $cajero);

    expect(fn () => app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '15',
        'concepto' => 'No entra',
    ], $cajero))->toThrow(ValidationException::class);

    $revision = app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '15',
        'concepto' => 'Ya se pagó',
        'ya_ocurrido' => true,
    ], $cajero);

    expect($revision->estado)->toBe(EstadoCajaChicaMovimiento::EnRevision)
        ->and($revision->afecta_saldo)->toBeFalse()
        ->and(app(ArqueoCajaSesion::class)->de($sesion->fresh())['teorico_efectivo'])->toBe('10.00')
        ->and(app(ArqueoCajaSesion::class)->de($sesion->fresh())['tiene_caja_chica'])->toBeTrue();
});

it('anula un turno vacío y obliga arqueo si solo hubo caja chica', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-vacio');
    habilitarCajon();
    $vacia = sesionDe($cajero, $sucursal, '0');

    $anulada = app(CerrarCajaSesion::class)->cerrar($vacia, [], $cajero);

    expect($anulada->estado_sesion)->toBe(EstadoCajaSesion::Anulada)
        ->and($anulada->cerrado_por_user_id)->toBe($cajero->id);

    $conGasto = sesionDe($cajero, $sucursal, '20');
    app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $conGasto->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '5',
        'concepto' => 'Útil',
    ], $cajero);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($conGasto->fresh(), [
        'medios_declarados' => declaracionDe($conGasto->fresh()),
    ], $cajero);
    $dia = app(ConsolidarCajasDelDia::class)->de($sucursal, now());

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Cerrada)
        ->and($cerrada->monto_cierre_teorico)->toBe('15.00')
        ->and($dia['turnos_vacios_ocultos'])->toBe(0)
        ->and(collect($dia['sesiones'])->pluck('id'))->toContain($cerrada->id);
});

it('bloquea el cierre con un vale pendiente y deja rendir el sobrante al día siguiente sin reabrir el arqueo', function () {
    Carbon::setTestNow('2026-11-12 10:00:00');
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-vale');
    habilitarCajon();
    $sesion = sesionDe($cajero, $sucursal, '20');
    $vale = app(EntregarVale::class)->entregar([
        'caja_sesion_id' => $sesion->id,
        'persona_recibe' => 'Ana',
        'monto' => '10',
        'concepto' => 'Compra menor',
    ], $cajero);

    expect(fn () => app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => declaracionDe($sesion->fresh()),
    ], $cajero))->toThrow(ValidationException::class);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => declaracionDe($sesion->fresh()),
        'cierre_vales_motivo' => 'El vale se rinde mañana.',
        'seguimiento_user_id' => $supervisor->id,
    ], $supervisor);

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Cerrada)
        ->and($cerrada->monto_cierre_teorico)->toBe('10.00')
        ->and($cerrada->cerrado_por_user_id)->toBe($supervisor->id)
        ->and($cerrada->cierre_vales_motivo)->toBe('El vale se rinde mañana.')
        ->and($vale->fresh()->estado)->toBe(EstadoCajaChicaMovimiento::PendienteRendir)
        ->and($vale->fresh()->seguimiento_user_id)->toBe($supervisor->id);

    Carbon::setTestNow('2026-11-13 09:00:00');
    $siguiente = sesionDe($cajero, $sucursal, '0');
    app(RendirVale::class)->rendir($vale->fresh(), [
        'monto_gasto' => '6',
        'monto_sobrante' => '4',
        'categoria_gasto_id' => $categoria->id,
        'destino_caja_sesion_id' => $siguiente->id,
        'numero_comprobante' => 'R-1',
    ], $cajero);

    expect($cerrada->fresh()->monto_cierre_teorico)->toBe('10.00')
        ->and(app(ArqueoCajaSesion::class)->de($siguiente->fresh())['teorico_efectivo'])->toBe('4.00')
        ->and(app(ArqueoCajaSesion::class)->de($siguiente->fresh())['sobrantes_caja_chica'])->toBe('4.00')
        ->and(CajaChicaMovimiento::query()->where('tipo', 'ingreso_sobrante')->count())->toBe(1)
        ->and($vale->fresh()->estado)->toBe(EstadoCajaChicaMovimiento::RendidoCompleto);

    expect(fn () => app(RendirVale::class)->rendir($vale->fresh(), [
        'monto_gasto' => '6',
        'monto_sobrante' => '4',
        'destino_caja_sesion_id' => $siguiente->id,
    ], $cajero))->toThrow(ValidationException::class);
});

it('adjunta el comprobante después del cierre sin mover el arqueo guardado', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-recibo');
    habilitarCajon();
    $sesion = sesionDe($cajero, $sucursal, '20');
    $gasto = app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '5',
        'concepto' => 'Falta el recibo',
    ], $cajero);
    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion->fresh(), [
        'medios_declarados' => declaracionDe($sesion->fresh()),
    ], $cajero);
    $medios = CajaSesionMedio::query()->where('caja_sesion_id', $cerrada->id)->count();

    $adjunto = app(AdjuntarComprobante::class)->adjuntar($gasto->fresh(), [
        'numero_comprobante' => 'F-9',
    ], $cajero);

    expect($adjunto->estado)->toBe(EstadoCajaChicaMovimiento::RendidoCompleto)
        ->and($cerrada->fresh()->monto_cierre_teorico)->toBe('15.00')
        ->and(CajaSesionMedio::query()->where('caja_sesion_id', $cerrada->id)->count())->toBe($medios);
});

it('rechaza rendir sin sobrante o adjuntar comprobante en un cajón ajeno sin acceso', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-acceso-rendicion');
    habilitarCajon();
    $ajeno = TenantUser::factory()->create();
    $ajeno->givePermissionTo(Permisos::PROPIA);
    $sesion = sesionDe($cajero, $sucursal, '20');
    $vale = app(EntregarVale::class)->entregar([
        'caja_sesion_id' => $sesion->id, 'monto' => '10', 'persona_recibe' => 'Ana', 'concepto' => 'Mandado',
    ], $cajero);
    $gasto = app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id, 'categoria_gasto_id' => $categoria->id, 'monto' => '5', 'concepto' => 'Pasaje',
    ], $cajero);

    expect(fn () => app(RendirVale::class)->rendir($vale, ['monto_gasto' => '10', 'monto_sobrante' => '0'], $ajeno))
        ->toThrow(ValidationException::class, 'No tenés acceso a ese movimiento');
    expect(fn () => app(AdjuntarComprobante::class)->adjuntar($gasto, ['numero_comprobante' => 'R-1'], $ajeno))
        ->toThrow(ValidationException::class, 'No tenés acceso a ese movimiento');

    expect($vale->fresh()->estado)->toBe(EstadoCajaChicaMovimiento::PendienteRendir)
        ->and($gasto->fresh()->numero_comprobante)->toBeNull();
});

it('registra el arqueo de entrega y exige motivo si el efectivo contado no calza', function () {
    ['supervisor' => $supervisor, 'cajero' => $cajero, 'sucursal' => $sucursal] = negocioDeCajaChica('cajachica-entrega-fondo');
    $fondos = app(FondosCajaChica::class);
    $fondo = $fondos->abrir([
        'sucursal_id' => $sucursal->id, 'responsable_id' => $supervisor->id, 'monto_apertura' => '65',
    ], $supervisor);

    expect(fn () => $fondos->cambiarResponsable($fondo, ['responsable_id' => $cajero->id, 'contado' => '63'], $supervisor))
        ->toThrow(ValidationException::class, 'tenés que justificarlo');
    expect($fondo->fresh()->responsable_id)->toBe($supervisor->id);

    $fondos->cambiarResponsable($fondo, [
        'responsable_id' => $cajero->id, 'contado' => '63', 'justificacion' => 'Faltan dos bolivianos al contar.',
    ], $supervisor);

    $arqueo = $fondo->arqueos()->latest('id')->firstOrFail();
    expect($fondo->fresh()->saldo_actual)->toBe('63.00')
        ->and($arqueo->teorico)->toBe('65.00')
        ->and($arqueo->contado)->toBe('63.00')
        ->and($arqueo->diferencia)->toBe('-2.00')
        ->and($arqueo->entrega_user_id)->toBe($supervisor->id)
        ->and($arqueo->recibe_user_id)->toBe($cajero->id);
});

it('no ve los movimientos de otro negocio', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'categoria' => $categoria] = negocioDeCajaChica('cajachica-a');
    habilitarCajon();
    $sesion = sesionDe($cajero, $sucursal, '20');
    app(RegistrarGasto::class)->registrar([
        'caja_sesion_id' => $sesion->id,
        'categoria_gasto_id' => $categoria->id,
        'monto' => '4',
        'concepto' => 'Del negocio A',
    ], $cajero);

    tenancy()->end();
    $otro = Tenant::factory()->create();
    $otro->domains()->create(['domain' => 'cajachica-b']);
    tenancy()->initialize($otro);

    expect(CajaChicaMovimiento::query()->count())->toBe(0)
        ->and(CajaSesion::query()->count())->toBe(0);
});
