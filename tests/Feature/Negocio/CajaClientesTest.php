<?php

use App\Caja\AbrirCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Enums\DepartamentoEmisionBolivia;
use App\Enums\EstadoCajaSesion;
use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Filament\Negocio\Pages\CajaDelNegocio;
use App\Filament\Negocio\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Negocio\Resources\Impuestos\Pages\ManageImpuestos;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\ClienteDocumento;
use App\Models\Impuesto;
use App\Models\Sucursal;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'monto_cierre_real' => '100',
    ], $supervisor);

    expect($cerrada->estado_sesion)->toBe(EstadoCajaSesion::Cerrada)
        ->and($cerrada->diferencia)->toBe('0.00');
});

it('exige justificación cuando el cierre tiene diferencia', function () {
    ['cajero' => $cajero, 'sucursal' => $sucursal] = negocioConCaja('desfase');
    $abrir = app(AbrirCajaSesion::class);
    $cerrar = app(CerrarCajaSesion::class);

    $sesion = $abrir->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '100',
    ], $cajero);

    expect(fn () => $cerrar->cerrar($sesion, [
        'monto_cierre_real' => '90',
    ], $cajero))->toThrow(ValidationException::class);

    $cerrada = $cerrar->cerrar($sesion->fresh(), [
        'monto_cierre_real' => '90',
        'justificacion_diferencia' => 'Faltó un billete de diez.',
    ], $cajero);

    expect($cerrada->diferencia)->toBe('-10.00')
        ->and($cerrada->justificacion_diferencia)->toBe('Faltó un billete de diez.')
        ->and($cerrada->supervisor_id)->toBeNull();
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

    Livewire::actingAs($cajero, 'tenant')
        ->test(CajaDelNegocio::class)
        ->fillForm([
            'monto_cierre_real' => '50',
        ])
        ->call('cerrar')
        ->assertHasNoFormErrors();

    expect(CajaSesion::query()->where('user_id', $cajero->id)->where('estado_sesion', EstadoCajaSesion::Cerrada)->count())->toBe(1);
});

it('guarda el supervisor cuando un supervisor cierra con diferencia', function () {
    ['supervisor' => $supervisor, 'sucursal' => $sucursal] = negocioConCaja('sup-desfase');

    $sesion = app(AbrirCajaSesion::class)->abrir([
        'sucursal_id' => $sucursal->id,
        'monto_apertura' => '200',
    ], $supervisor);

    $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion, [
        'monto_cierre_real' => '205',
        'justificacion_diferencia' => 'Sobrante de propina.',
    ], $supervisor);

    expect($cerrada->diferencia)->toBe('5.00')
        ->and($cerrada->supervisor_id)->toBe($supervisor->id);
});
