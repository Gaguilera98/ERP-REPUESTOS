<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Enums\DepartamentoEmisionBolivia;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Filament\Negocio\Pages\CajaDelNegocio;
use App\Filament\Negocio\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Negocio\Resources\Impuestos\Pages\ManageImpuestos;
use App\Livewire\Negocio\Caja\SesionesDelDia;
use App\Livewire\Negocio\Caja\VentasDeSesion;
use App\Models\Almacen;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\ClienteDocumento;
use App\Models\Impuesto;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\Sucursal;
use App\Models\SucursalAlmacen;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
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
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

/**
 * @return array{supervisor: TenantUser, cajero: TenantUser, sucursal: Sucursal}
 */
/**
 * @return array<string, string>
 */
function mediosParaCerrar($sesion, array $cambios = []): array
{
    $medios = [];

    foreach (app(ArqueoCajaSesion::class)->de($sesion)['por_metodo'] as $fila) {
        $medios[$fila['metodo']] = $cambios[$fila['metodo']] ?? $fila['esperado'];
    }

    return $medios;
}

function negocioConCaja(string $dominio = 'caja'): array
{
    $tenant = Tenant::factory()->create();
    $tenant->domains()->create(['domain' => $dominio]);
    tenancy()->initialize($tenant);

    $supervisor = TenantUser::factory()->create();
    $supervisor->assignRole('supervisor');

    $cajero = TenantUser::factory()->create();
    $cajero->assignRole('cajero');

    $sucursal = Sucursal::factory()->create(['nombre' => 'Centro']);
    $sucursal->usuarios()->attach([$cajero->id, $supervisor->id], ['tenant_id' => $tenant->id]);

    return compact('supervisor', 'cajero', 'sucursal');
}

it('siembra CI y NIT con atributos dinámicos al crear el tenant', function () {
    negocioConCaja('seed-docs');

    $ci = TipoDocumentoIdentidad::query()->where('codigo', 'ci')->with('atributos')->first();
    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->with('atributos')->first();

    expect($ci)->not->toBeNull()
        ->and($nit)->not->toBeNull()
        ->and($ci->atributos->pluck('clave')->all())->toBe(['numero', 'complemento', 'lugar_emision'])
        ->and($nit->atributos->pluck('clave')->all())->toBe(['numero'])
        ->and($ci->atributos->firstWhere('clave', 'lugar_emision')?->opciones)->toContain('SCZ')
        ->and($ci->atributos->firstWhere('clave', 'complemento')?->identifica)->toBeTrue();
});

it('crea persona y empresa con valores dinámicos y respeta identidad única', function () {
    ['supervisor' => $supervisor] = negocioConCaja();

    $nit = TipoDocumentoIdentidad::query()->where('codigo', 'nit')->firstOrFail();
    $ci = TipoDocumentoIdentidad::query()->where('codigo', 'ci')->firstOrFail();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateCliente::class)
        ->fillForm([
            'tipo_cliente' => TipoCliente::Empresa->value,
            'razon_social' => 'Taller Norte',
            'tipo_precio' => TipoPrecioCliente::Mayor->value,
            'documentos' => [
                [
                    'tipo_documento_identidad_id' => $nit->id,
                    'valores_json' => [
                        'numero' => '1234567',
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $empresa = Cliente::query()->where('razon_social', 'Taller Norte')->first();

    expect($empresa)->not->toBeNull()
        ->and($empresa->documentos)->toHaveCount(1)
        ->and($empresa->documentos->first()->valores_json['numero'])->toBe('1234567')
        ->and($empresa->documentos->first()->identidad)->toBe('1234567');

    Livewire::actingAs($supervisor, 'tenant')
        ->test(CreateCliente::class)
        ->fillForm([
            'tipo_cliente' => TipoCliente::PersonaNatural->value,
            'nombres' => 'María',
            'apellidos' => 'Pérez',
            'tipo_precio' => TipoPrecioCliente::Menor->value,
            'documentos' => [
                [
                    'tipo_documento_identidad_id' => $ci->id,
                    'valores_json' => [
                        'numero' => '8765432',
                        'complemento' => '1K',
                        'lugar_emision' => DepartamentoEmisionBolivia::SantaCruz->value,
                    ],
                ],
                [
                    'tipo_documento_identidad_id' => $nit->id,
                    'valores_json' => [
                        'numero' => '9988776',
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $persona = Cliente::query()->where('nombres', 'María')->where('apellidos', 'Pérez')->first();

    expect($persona)->not->toBeNull()
        ->and($persona->razon_social)->toBe('María Pérez')
        ->and($persona->nombre_mostrar)->toBe('María Pérez')
        ->and($persona->documentos)->toHaveCount(2)
        ->and($persona->documentos->firstWhere('tipo_documento_identidad_id', $ci->id)?->identidad)->toBe('8765432|1k');

    expect(fn () => ClienteDocumento::factory()->create([
        'cliente_id' => Cliente::factory()->create()->id,
        'tipo_documento_identidad_id' => $ci->id,
        'valores_json' => [
            'numero' => '8765432',
            'complemento' => '1K',
            'lugar_emision' => DepartamentoEmisionBolivia::LaPaz->value,
        ],
    ]))->toThrow(QueryException::class);

    ClienteDocumento::factory()->create([
        'cliente_id' => Cliente::factory()->personaNatural()->create()->id,
        'tipo_documento_identidad_id' => $ci->id,
        'valores_json' => [
            'numero' => '8765432',
            'complemento' => '2B',
            'lugar_emision' => DepartamentoEmisionBolivia::LaPaz->value,
        ],
    ]);

    expect(ClienteDocumento::query()->where('tipo_documento_identidad_id', $ci->id)->count())->toBe(2);
    expect(TipoDocumentoAtributo::query()->count())->toBeGreaterThanOrEqual(4);
});

it('abre y cierra la caja sin diferencia y no permite abrir dos en la misma sucursal', function () {
    ['supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('turno');
    $abrir = app(AbrirCajaSesion::class);
    $cerrar = app(CerrarCajaSesion::class);

    $sesion = $abrir->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '100',
    ], $supervisor);

    expect($sesion->estado_sesion)->toBe(EstadoCajaSesion::Abierta)
        ->and($sesion->monto_apertura)->toBe('100.00');

    expect(fn () => $abrir->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '50',
    ], $supervisor))->toThrow(ValidationException::class);

    $cerrada = $cerrar->cerrar($sesion, [
        'medios_declarados' => mediosParaCerrar($sesion),
    ], $supervisor);

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Anulada)
        ->and($cerrada->diferencia)->toBe('0.00');
});

it('anula el turno sin ventas en vez de dejar un cierre vacío', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('desfase');
    $abrir = app(AbrirCajaSesion::class);
    $cerrar = app(CerrarCajaSesion::class);

    $sesion = $abrir->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '100',
    ], $cajero);

    $anulada = $cerrar->cerrar($sesion, [], $cajero);

    expect($anulada->estado_sesion)->toBe(EstadoCajaSesion::Anulada)
        ->and($anulada->justificacion_diferencia)->toBeNull();
});

it('deja al cajero abrir caja y le niega administrar impuestos', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('roles');

    expect($cajero->can('create', Impuesto::class))->toBeFalse()
        ->and($cajero->can('update', Impuesto::factory()->create()))->toBeFalse()
        ->and($cajero->can('create', Cliente::class))->toBeTrue()
        ->and($cajero->can('viewAny', TipoDocumentoIdentidad::class))->toBeTrue()
        ->and($cajero->can('create', TipoDocumentoIdentidad::class))->toBeFalse()
        ->and($cajero->can('create', CajaSesion::class))->toBeTrue()
        ->and($supervisor->can('create', Impuesto::class))->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')
        ->test(ManageImpuestos::class)
        ->assertSuccessful()
        ->assertActionHidden('create');

    Livewire::actingAs($cajero, 'tenant')
        ->test(CajaDelNegocio::class)
        ->fillForm([
            'sucursal_id' => $sucursal->id,
            'monto_apertura' => '50',
        ])
        ->call('abrir')
        ->assertHasNoFormErrors();

    expect(CajaSesion::query()->where('user_id', $cajero->id)->where('estado_sesion', EstadoCajaSesion::Abierta)->count())->toBe(1);

    $sesionAbierta = CajaSesion::query()
        ->where('user_id', $cajero->id)
        ->where('estado_sesion', EstadoCajaSesion::Abierta)
        ->firstOrFail();

    Livewire::actingAs($cajero, 'tenant')
        ->test(CajaDelNegocio::class)
        ->fillForm([
            'medios_declarados' => mediosParaCerrar($sesionAbierta),
        ])
        ->call('cerrar')
        ->assertHasNoFormErrors();

    expect(CajaSesion::query()->where('user_id', $cajero->id)->where('estado_sesion', EstadoCajaSesion::Anulada)->count())->toBe(1);
});

it('anula el turno vacío aunque un supervisor lo cierre', function () {
    ['supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('sup-desfase');

    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '200',
    ], $supervisor);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion, [
        'medios_declarados' => mediosParaCerrar($sesion, [
            MetodoPago::Efectivo->value => '205',
        ]),
        'justificacion_diferencia' => 'Sobrante de propina.',
    ], $supervisor);

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Anulada)
        ->and($cerrada->supervisor_id)->toBeNull();
});

it('pagina mis sesiones y filtra por un día sin mostrar las de otro vendedor', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('historial-sesiones');
    $sesiones = CajaSesion::factory()->count(12)->sequence(fn ($sequence) => [
        'fecha_apertura' => now()->subDays($sequence->index + 1)->startOfDay(),
    ])->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada]);
    $ajena = CajaSesion::factory()->create(['user_id' => $supervisor->id, 'sucursal_id' => $sucursal->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(CajaDelNegocio::class)
        ->assertCanSeeTableRecords($sesiones->take(10), inOrder: true)
        ->assertCanNotSeeTableRecords([$sesiones[10], $sesiones[11], $ajena])
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords([$sesiones[10], $sesiones[11]])
        ->filterTable('dia', ['fecha' => $sesiones[0]->fecha_apertura->toDateString()])
        ->assertCanSeeTableRecords([$sesiones[0]])
        ->assertCountTableRecords(1)
        ->resetTableFilters()
        ->assertCountTableRecords(12);
});

it('muestra las ventas de la sesión elegida sin duplicar pagos divididos ni mezclar sesiones', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('detalle-sesion');
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $sesion = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id]);
    $venta = Venta::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'codigo' => 'VNT-PRUEBA-SESION']);
    foreach (['40.00', '60.00'] as $monto) {
        $pago = Pago::factory()->create(['caja_sesion_id' => $sesion->id, 'monto' => $monto]);
        PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id, 'monto' => $monto]);
    }
    $otraVenta = Venta::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'codigo' => 'VNT-OTRA-SESION']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')->test(CajaDelNegocio::class)
        ->mountAction(TestAction::make('verSesiones')->table($sesion))
        ->assertActionMounted(TestAction::make('verSesiones')->table($sesion));

    $tabla = Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => $sesion->fecha_apertura->toDateString()])
        ->mountAction(TestAction::make('verSesion')->table($sesion))
        ->assertActionMounted(TestAction::make('verSesion')->table($sesion));
    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $sesion->id])
        ->assertCanSeeTableRecords([$venta])
        ->assertCanNotSeeTableRecords([$otraVenta])
        ->assertCountTableRecords(1)
        ->searchTable('PRUEBA-SESION')
        ->assertCanSeeTableRecords([$venta])
        ->searchTable('OTRA-SESION')
        ->assertCountTableRecords(0);

    $detalle = $tabla->instance()->getMountedAction()->getModalContent()->getData();

    expect($detalle['arqueo']['total_cobros'])->toBe('100.00');
});

it('rechaza consultar el detalle de la sesión de otro vendedor', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('detalle-ajeno');
    $ajena = CajaSesion::factory()->create(['user_id' => $supervisor->id, 'sucursal_id' => $sucursal->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => $ajena->fecha_apertura->toDateString()])
        ->mountAction(TestAction::make('verSesion')->table($ajena))
        ->assertActionNotMounted();
});

it('cuenta un turno anterior sin sustituir el turno actual ni su declaración', function () {
    ['cajero' => $cajero, 'supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('conteo-turno-anterior');
    $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
    $anterior = CajaSesion::factory()->create([
        'user_id' => $cajero->id,
        'sucursal_id' => $sucursal->id,
        'estado_sesion' => EstadoCajaSesion::PendienteArqueo,
        'fecha_apertura' => now()->subDay(),
        'monto_apertura' => '20.00',
    ]);
    Pago::factory()->create(['caja_sesion_id' => $anterior->id, 'fecha_pago' => now()->subDay()]);
    $ajena = CajaSesion::factory()->create([
        'user_id' => $supervisor->id,
        'sucursal_id' => $sucursal->id,
        'estado_sesion' => EstadoCajaSesion::PendienteArqueo,
        'fecha_apertura' => now()->subDay(),
    ]);
    $actual = CajaSesion::factory()->create([
        'user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'monto_apertura' => '30.00',
    ]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $conteo = fn (CajaSesion $sesion): TestAction => TestAction::make('contarTurnoAnterior')->arguments(['sesionId' => $sesion->id]);
    $pagina = Livewire::actingAs($cajero, 'tenant')->test(CajaDelNegocio::class)
        ->set('data.medios_declarados.efectivo', '31')
        ->mountAction($conteo($ajena))->assertActionNotMounted()
        ->mountAction($conteo($actual))->assertActionNotMounted();

    $pagina->call('contar', $anterior->id)->assertActionMounted('contarTurnoAnterior')
        ->assertSee('Turno actual · Sesión #'.$actual->id)
        ->assertSchemaStateSet(['medios_declarados.efectivo' => '120.00'])
        ->assertSet('data.medios_declarados.efectivo', '31');
    expect($pagina->instance()->getMountedAction()->getModalHeading())->toBe('Turno anterior · 10/11/2026');
    $pagina->unmountAction()
        ->assertSet('data.medios_declarados.efectivo', '31');
    expect($anterior->refresh()->estado_sesion)->toBe(EstadoCajaSesion::PendienteArqueo);

    $pagina->mountAction($conteo($anterior))
        ->fillForm(['medios_declarados.efectivo' => '119'])
        ->callMountedAction()
        ->assertHasActionErrors(['justificacion_diferencia' => 'required'])
        ->fillForm(['justificacion_diferencia' => 'Faltó un boliviano al contar.'])
        ->callMountedAction()
        ->assertNotified('Caja cerrada')
        ->assertSet('data.medios_declarados.efectivo', '31');

    expect($anterior->refresh()->estado_sesion)->toBe(EstadoCajaSesion::Cerrada)
        ->and($anterior->monto_cierre_real)->toBe('119.00')
        ->and($actual->refresh()->estado_sesion)->toBe(EstadoCajaSesion::Abierta);
});

it('abre el historial desde las acciones superiores de caja', function () {
    ['cajero' => $cajero] = negocioConCaja('menu-historial');
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(CajaDelNegocio::class)
        ->assertActionExists('historialSesiones')
        ->callAction('historialSesiones')
        ->assertDispatched('open-modal', id: 'historial-sesiones-caja');
});

it('agrupa varias sesiones por apertura y conserva el día aunque cierre después de medianoche', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'supervisor' => $supervisor] = negocioConCaja('dias-agrupados');
    $primera = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'fecha_apertura' => '2026-10-01 18:00:00', 'fecha_cierre' => '2026-10-01 19:00:00']);
    $segunda = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'fecha_apertura' => '2026-10-01 20:00:00', 'fecha_cierre' => '2026-10-02 00:20:00']);
    $otra = CajaSesion::factory()->create(['user_id' => $supervisor->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'fecha_apertura' => '2026-10-01 20:00:00']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $pagina = Livewire::actingAs($cajero, 'tenant')->test(CajaDelNegocio::class)
        ->assertCountTableRecords(1)
        ->mountAction(TestAction::make('verSesiones')->table($primera))
        ->assertActionMounted(TestAction::make('verSesiones')->table($primera));

    $dias = $pagina->instance()->getTableRecords();
    expect((int) $dias->first()->sesiones)->toBe(2);
    expect((int) $dias->first()->cerradas)->toBe(2);
    $fecha = $pagina->instance()->getMountedAction()->getModalContent()->getData()['fecha'];
    Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => $fecha])
        ->assertCanSeeTableRecords([$primera, $segunda])
        ->assertCanNotSeeTableRecords([$otra]);
});

it('pagina sesiones del día y permite ampliar a 25 filas', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('sesiones-paginadas');
    $sesiones = CajaSesion::factory()->count(12)->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $tabla = Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => now()->toDateString()]);
    expect($tabla->instance()->getTableRecords())->toHaveCount(10);
    $tabla->call('gotoPage', 2, 'sesionesDelDiaPage');
    expect($tabla->instance()->getTableRecords())->toHaveCount(2);
    $tabla->set('tableRecordsPerPage', 25)->assertCanSeeTableRecords($sesiones);
    expect($tabla->instance()->getTableRecords())->toHaveCount(12);
    $tabla->mountAction(TestAction::make('verSesion')->table($sesiones[0]))
        ->assertActionMounted(TestAction::make('verSesion')->table($sesiones[0]));
});

it('pagina ventas y bloquea el acceso a ventas de sesiones ajenas', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal, 'supervisor' => $supervisor] = negocioConCaja('ventas-paginadas');
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $sesion = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id]);
    $ventas = Venta::factory()->count(12)->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id]);
    foreach ($ventas as $venta) {
        $pago = Pago::factory()->create(['caja_sesion_id' => $sesion->id]);
        PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id]);
    }
    $ajena = CajaSesion::factory()->create(['user_id' => $supervisor->id, 'sucursal_id' => $sucursal->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $tabla = Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $sesion->id]);
    expect($tabla->instance()->getTableRecords())->toHaveCount(10);
    $tabla->call('gotoPage', 2, 'ventasDeSesionPage');
    expect($tabla->instance()->getTableRecords())->toHaveCount(2);
    $tabla->set('tableRecordsPerPage', 25)->assertCanSeeTableRecords($ventas);
    expect($tabla->instance()->getTableRecords())->toHaveCount(12);
    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $ajena->id])->assertNotFound();
});

it('abre el detalle completo al primer intento y al alternar entre sesiones', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('modal-sesion-repetida');
    $primera = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'monto_apertura' => '17.00']);
    $segunda = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada, 'monto_apertura' => '34.00']);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));
    $tabla = Livewire::actingAs($cajero, 'tenant')->test(SesionesDelDia::class, ['fecha' => now()->toDateString()]);

    foreach ([$primera, $segunda, $primera] as $sesion) {
        $tabla->mountAction(TestAction::make('verSesion')->table($sesion))
            ->assertActionMounted(TestAction::make('verSesion')->table($sesion));
        $accion = $tabla->instance()->getMountedAction();
        expect($accion->getModalHeading())->toBe('Sesión #'.$sesion->id);
        $vista = $accion->getModalContent();
        expect($vista->getData()['sesion']->id)->toBe($sesion->id);
        expect($vista->getData()['arqueo']['apertura'])->toBe($sesion->monto_apertura);
        expect($vista->render())->toContain('Arqueo por medio');
        $tabla->unmountAction();
    }
});

it('abre el detalle de venta con cantidades, precios históricos y montos aplicados', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('comprobante-venta');
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $sesion = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id]);
    $venta = Venta::factory()->create([
        'user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id,
        'subtotal' => '120.00', 'descuento' => '30.00', 'total' => '90.00',
        'notes_internas' => '<script>alert("nota")</script>',
    ]);
    $pieza = ProductoVariante::factory()->create(['sku' => 'BUJIA-HIST', 'precio_venta' => '9999.00']);
    $pieza->productoMaestro->update(['nombre' => 'BUJÍA HISTÓRICA']);
    $unidad = UnidadMedida::factory()->create(['nombre' => 'Paquete de prueba', 'simbolo' => 'paq']);
    VentaDetalle::factory()->create([
        'venta_id' => $venta->id, 'producto_variante_id' => $pieza->id, 'unidad_medida_id' => $unidad->id,
        'cantidad' => '2', 'factor_a_base' => '4', 'cantidad_base' => '8', 'precio_unitario' => '15.00',
        'descuento_aplicado' => '20.00', 'total_linea' => '100.00',
    ]);
    foreach ([['efectivo', '40.00'], ['qr_externo', '50.00']] as [$metodo, $monto]) {
        $pago = Pago::factory()->create(['caja_sesion_id' => $sesion->id, 'metodo_pago' => $metodo, 'monto' => '800.00']);
        PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id, 'monto' => $monto]);
    }
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    $tabla = Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $sesion->id])
        ->mountAction(TestAction::make('verDetalle')->table($venta))
        ->assertActionMounted(TestAction::make('verDetalle')->table($venta));

    $accion = $tabla->instance()->getMountedAction();
    expect($accion->getModalHeading())->toBe('Nota de venta · '.$venta->codigo);
    $contenido = $accion->getModalContent()->render();
    expect($contenido)->toContain('Consumidor final', 'Bujía Histórica', 'BUJIA-HIST', '2 paq', '8 pza', 'BOB 15.00', 'BOB 100.00', 'BOB 120.00', 'BOB 30.00', 'BOB 90.00', 'Efectivo', 'QR externo', 'BOB 40.00', 'BOB 50.00');
    expect($contenido)->not->toContain('Impuestos registrados', '9999.00', 'BOB 800.00', '<script>');
    expect($contenido)->toContain('&lt;script&gt;');
});

it('impide abrir el detalle de una venta ajena a la sesión seleccionada', function (bool $otroNegocio) {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('detalle-venta-aislado');
    $tenant = tenant();
    $primera = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'estado_sesion' => EstadoCajaSesion::Cerrada]);
    $usuarioVenta = $cajero;

    if ($otroNegocio) {
        tenancy()->end();
        tenancy()->initialize(Tenant::factory()->create());
        $usuarioVenta = TenantUser::factory()->create();
        $sucursal = Sucursal::factory()->create();
    }

    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $segunda = CajaSesion::factory()->create(['user_id' => $usuarioVenta->id, 'sucursal_id' => $sucursal->id]);
    $venta = Venta::factory()->create(['user_id' => $usuarioVenta->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id]);
    $pago = Pago::factory()->create(['caja_sesion_id' => $segunda->id]);
    PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id]);

    if ($otroNegocio) {
        tenancy()->end();
        tenancy()->initialize($tenant);
    }

    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $primera->id])
        ->mountAction(TestAction::make('verDetalle')->table($venta))
        ->assertActionNotMounted();
})->with(['otra sesión propia' => false, 'otro negocio' => true]);

it('exige permiso para consultar el detalle de una venta', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('detalle-venta-permiso');
    $cajero->syncRoles([]);
    $cajero->givePermissionTo('View:CajaDelNegocio');
    $almacen = Almacen::factory()->create();
    SucursalAlmacen::query()->create(['sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id, 'es_principal' => true]);
    $sesion = CajaSesion::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id]);
    $venta = Venta::factory()->create(['user_id' => $cajero->id, 'sucursal_id' => $sucursal->id, 'almacen_id' => $almacen->id]);
    $pago = Pago::factory()->create(['caja_sesion_id' => $sesion->id]);
    PagoAplicacion::factory()->create(['pago_id' => $pago->id, 'venta_id' => $venta->id]);
    Filament::setCurrentPanel(Filament::getPanel('negocio'));

    Livewire::actingAs($cajero, 'tenant')->test(VentasDeSesion::class, ['sesionId' => $sesion->id])
        ->assertActionHidden(TestAction::make('verDetalle')->table($venta))
        ->mountAction(TestAction::make('verDetalle')->table($venta))
        ->assertActionNotMounted();
});
