<?php

namespace App\Filament\Negocio\Pages;

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

class CajaDelNegocio extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Caja';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Caja';

    protected static ?string $slug = 'caja';

    protected string $view = 'filament.negocio.pages.caja-del-negocio';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser
            && $usuario->can('viewAny', CajaSesion::class);
    }

    public function mount(): void
    {
        $this->rellenarFormulario();
    }

    public function form(Schema $schema): Schema
    {
        $moneda = NegocioConfiguracion::codigoMoneda();
        $camposMedios = [];

        foreach (MetodoPago::cases() as $metodo) {
            $camposMedios[] = TextInput::make('medios_declarados.'.$metodo->value)
                ->label($metodo === MetodoPago::Efectivo ? 'Efectivo contado' : $metodo->etiqueta().' declarado')
                ->prefix($moneda)
                ->numeric()
                ->minValue(0)
                ->required()
                ->live(onBlur: true)
                ->autofocus(fn (): bool => $metodo === MetodoPago::Efectivo)
                ->hintIcon(function (Get $get) use ($metodo): ?Heroicon {
                    return $this->medioTieneDiferencia($metodo->value, $get('medios_declarados'))
                        ? Heroicon::ExclamationCircle
                        : null;
                })
                ->hintColor(function (Get $get) use ($metodo): ?string {
                    return $this->medioTieneDiferencia($metodo->value, $get('medios_declarados'))
                        ? 'danger'
                        : null;
                })
                ->extraFieldWrapperAttributes(function (Get $get) use ($metodo): array {
                    if (! $this->medioTieneDiferencia($metodo->value, $get('medios_declarados'))) {
                        return [];
                    }

                    return [
                        'class' => 'fi-fo-field-wrp-error',
                    ];
                })
                ->helperText(function (Get $get) use ($metodo, $moneda): string|HtmlString {
                    $esperado = $this->esperadoDeMedio($metodo->value);

                    if ($esperado === null) {
                        return '';
                    }

                    $declarados = $get('medios_declarados');
                    $dif = $this->diferenciaDeMedio(
                        $metodo->value,
                        is_array($declarados) ? ($declarados[$metodo->value] ?? null) : null,
                    );

                    if ($dif !== null && $dif !== '0.00') {
                        $etiquetaDif = str_starts_with($dif, '-')
                            ? 'Dif. '.$moneda.' '.$dif
                            : 'Dif. +'.$moneda.' '.$dif;

                        return new HtmlString(
                            '<span class="font-semibold text-danger-600 dark:text-danger-400">'.e($etiquetaDif).'</span>'
                            .' · Esperado '.$moneda.' '.$esperado
                        );
                    }

                    $pista = $metodo === MetodoPago::Efectivo
                        ? 'Esperado en cajón'
                        : 'Esperado según cobros';

                    return $pista.': '.$moneda.' '.$esperado;
                });
        }

        return $schema
            ->components([
                Grid::make(1)
                    ->visible(fn (): bool => $this->sesionActual() === null)
                    ->schema([
                        Select::make('sucursal_id')
                            ->label('Sucursal')
                            ->options(fn (): array => Sucursal::query()
                                ->where('is_active', true)
                                ->orderBy('nombre')
                                ->pluck('nombre', 'id')
                                ->all())
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('monto_apertura')
                            ->label('Monto de apertura')
                            ->prefix($moneda)
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default('0'),
                    ]),
                Grid::make([
                    'default' => 1,
                    'sm' => 2,
                    'xl' => 4,
                ])
                    ->visible(fn (): bool => $this->sesionActual() instanceof CajaSesion)
                    ->schema($camposMedios),
                Textarea::make('justificacion_diferencia')
                    ->label('Justificación de la diferencia')
                    ->placeholder('Ingresá la justificación (obligatoria)...')
                    ->rows(3)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => $this->hayDiferenciaEnMedios($get('medios_declarados')))
                    ->required(fn (Get $get): bool => $this->hayDiferenciaEnMedios($get('medios_declarados')))
                    ->helperText('La justificación es obligatoria para cerrar con descuadre.')
                    ->live(debounce: 300),
            ])
            ->statePath('data');
    }

    public function abrir(): void
    {
        $usuario = auth('tenant')->user();

        abort_unless($usuario instanceof TenantUser && $usuario->can('create', CajaSesion::class), 403);

        app(AbrirCajaSesion::class)->abrir($this->form->getState(), $usuario);

        Notification::make()
            ->title('Caja abierta')
            ->body('El turno quedó listo para cobrar en mostrador.')
            ->success()
            ->send();

        $this->rellenarFormulario();
    }

    public function cerrar(): void
    {
        $usuario = auth('tenant')->user();
        $sesion = $this->sesionActual();

        abort_unless(
            $usuario instanceof TenantUser
            && $sesion instanceof CajaSesion
            && $usuario->can('update', $sesion),
            403,
        );

        app(CerrarCajaSesion::class)->cerrar($sesion, $this->form->getState(), $usuario);

        Notification::make()
            ->title('Caja cerrada')
            ->body('El arqueo del turno se registró correctamente.')
            ->success()
            ->send();

        $this->rellenarFormulario();
    }

    /**
     * @return Collection<int, CajaSesion>
     */
    public function historial(): Collection
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return collect();
        }

        return CajaSesion::query()
            ->with(['sucursal', 'medios'])
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Cerrada)
            ->orderByDesc('fecha_cierre')
            ->limit(10)
            ->get();
    }

    /**
     * @return array{
     *     apertura: string,
     *     cobros_efectivo: string,
     *     reembolsos_efectivo: string,
     *     teorico_efectivo: string,
     *     total_cobros: string,
     *     total_reembolsos: string,
     *     por_metodo: list<array{metodo: string, etiqueta: string, cobros: string, reembolsos: string, neto: string, esperado: string}>
     * }|null
     */
    public function arqueoTurno(): ?array
    {
        $sesion = $this->sesionActual();

        if (! $sesion instanceof CajaSesion) {
            return null;
        }

        return app(ArqueoCajaSesion::class)->de($sesion);
    }

    public function moneda(): string
    {
        return NegocioConfiguracion::codigoMoneda();
    }

    public function tieneSesionAbierta(): bool
    {
        return $this->sesionActual() instanceof CajaSesion;
    }

    public function sesionActual(): ?CajaSesion
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return null;
        }

        return CajaSesion::query()
            ->with('sucursal')
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->orderByDesc('fecha_apertura')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $declarados
     * @return array<string, string>
     */
    public function diferenciasPorMedio(array $declarados): array
    {
        $arqueo = $this->arqueoTurno();
        $salida = [];

        if ($arqueo === null) {
            return $salida;
        }

        foreach ($arqueo['por_metodo'] as $fila) {
            $dif = $this->diferenciaDeMedio($fila['metodo'], $declarados[$fila['metodo']] ?? null);
            $salida[$fila['metodo']] = $dif ?? '0.00';
        }

        return $salida;
    }

    private function rellenarFormulario(): void
    {
        $sesion = $this->sesionActual();

        if ($sesion instanceof CajaSesion) {
            $arqueo = app(ArqueoCajaSesion::class)->de($sesion);
            $medios = [];

            foreach ($arqueo['por_metodo'] as $fila) {
                $medios[$fila['metodo']] = $fila['esperado'];
            }

            $this->form->fill([
                'medios_declarados' => $medios,
                'justificacion_diferencia' => null,
            ]);

            return;
        }

        $this->form->fill([
            'sucursal_id' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->value('id'),
            'monto_apertura' => '0.00',
        ]);
    }

    private function esperadoDeMedio(string $metodo): ?string
    {
        $arqueo = $this->arqueoTurno();

        if ($arqueo === null) {
            return null;
        }

        foreach ($arqueo['por_metodo'] as $fila) {
            if ($fila['metodo'] === $metodo) {
                return $fila['esperado'];
            }
        }

        return null;
    }

    private function diferenciaDeMedio(string $metodo, mixed $valor): ?string
    {
        $esperado = $this->esperadoDeMedio($metodo);

        if ($esperado === null || ! is_numeric($valor)) {
            return null;
        }

        return Montos::dos(bcsub(
            Montos::dos((string) $valor),
            $esperado,
            4,
        ));
    }

    private function medioTieneDiferencia(string $metodo, mixed $declarados): bool
    {
        if (! is_array($declarados)) {
            return false;
        }

        $dif = $this->diferenciaDeMedio($metodo, $declarados[$metodo] ?? null);

        return $dif !== null && $dif !== '0.00';
    }

    private function hayDiferenciaEnMedios(mixed $declarados): bool
    {
        if (! is_array($declarados)) {
            return false;
        }

        foreach ($this->diferenciasPorMedio($declarados) as $diferencia) {
            if ($diferencia !== '0.00') {
                return true;
            }
        }

        return false;
    }
}
