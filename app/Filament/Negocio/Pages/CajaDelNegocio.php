<?php

namespace App\Filament\Negocio\Pages;

use App\Caja\AbrirCajaSesion;
use App\Caja\ArqueoCajaSesion;
use App\Caja\CerrarCajaSesion;
use App\Caja\CongelarTurnosVencidos;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

class CajaDelNegocio extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;

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

    public bool $confirmandoCierre = false;

    public ?int $sesionEnConteo = null;

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser
            && (
                $usuario->can('View:CajaDelNegocio')
                || $usuario->can('viewAny', CajaSesion::class)
            );
    }

    public function mount(): void
    {
        $usuario = auth('tenant')->user();

        if ($usuario instanceof TenantUser) {
            app(CongelarTurnosVencidos::class)->de($usuario);
        }

        $this->rellenarFormulario();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('historialSesiones')
                ->label('Historial de sesiones')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->action(function (): void {
                    $this->dispatch('open-modal', id: 'historial-sesiones-caja');
                }),
        ];
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
                    ->visible(fn (): bool => $this->sesionParaCerrar() === null)
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
                Grid::make(1)
                    ->extraAttributes(['class' => 'caja-declaracion-medios'])
                    ->visible(fn (): bool => $this->sesionParaCerrar() instanceof CajaSesion)
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

    public function revisarCierre(): void
    {
        $this->form->getState();
        $this->confirmandoCierre = true;
    }

    public function cancelarConfirmacion(): void
    {
        $this->confirmandoCierre = false;
    }

    public function contar(int $sesionId): void
    {
        $this->sesionEnConteo = $sesionId;
        $this->confirmandoCierre = false;
        $this->rellenarFormulario();
    }

    public function cerrar(): void
    {
        $usuario = auth('tenant')->user();
        $sesion = $this->sesionParaCerrar();

        abort_unless(
            $usuario instanceof TenantUser
            && $sesion instanceof CajaSesion
            && $usuario->can('update', $sesion),
            403,
        );

        $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion, $this->form->getState(), $usuario);
        $anulada = $cerrada->estado_sesion === EstadoCajaSesion::Anulada;

        Notification::make()
            ->title($anulada ? 'Turno anulado' : 'Caja cerrada')
            ->body($anulada
                ? 'No tuvo ventas, así que no entra en el día.'
                : 'El arqueo del turno se registró correctamente.')
            ->success()
            ->send();

        $this->confirmandoCierre = false;
        $this->sesionEnConteo = null;
        $this->rellenarFormulario();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Mis días de caja')
            ->extraAttributes(['class' => 'caja-mobile-table'])
            ->description('Una fila por día de apertura. Entrá en Ver sesiones para consultar los turnos de esa fecha.')
            ->query(CajaSesion::query()
                ->where('user_id', auth('tenant')->id())
                ->selectRaw('MIN(id) as id, DATE(fecha_apertura) as dia, COUNT(*) as sesiones')
                ->selectRaw("SUM(CASE WHEN estado_sesion = 'abierta' THEN 1 ELSE 0 END) as abiertas")
                ->selectRaw("SUM(CASE WHEN estado_sesion = 'pendiente_arqueo' THEN 1 ELSE 0 END) as pendientes")
                ->selectRaw("SUM(CASE WHEN estado_sesion = 'cerrada' THEN 1 ELSE 0 END) as cerradas")
                ->selectRaw("SUM(CASE WHEN estado_sesion = 'anulada' THEN 1 ELSE 0 END) as anuladas")
                ->groupByRaw('DATE(fecha_apertura)'))
            ->defaultSort('dia', 'desc')
            ->defaultKeySort(false)
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('dia')->label('Día')->extraCellAttributes(['data-label' => 'Día'])->date('d/m/Y'),
                TextColumn::make('sesiones')->label('Sesiones')->extraCellAttributes(['data-label' => 'Sesiones']),
                TextColumn::make('abiertas')->label('Abiertas')->extraCellAttributes(['data-label' => 'Abiertas'])->badge()->color(EstadoCajaSesion::Abierta->getColor()),
                TextColumn::make('pendientes')->label('Por contar')->extraCellAttributes(['data-label' => 'Por contar'])->badge()->color(EstadoCajaSesion::PendienteArqueo->getColor()),
                TextColumn::make('cerradas')->label('Cerradas')->extraCellAttributes(['data-label' => 'Cerradas'])->badge()->color(EstadoCajaSesion::Cerrada->getColor()),
                TextColumn::make('anuladas')->label('Anuladas')->extraCellAttributes(['data-label' => 'Anuladas'])->badge()->color(EstadoCajaSesion::Anulada->getColor()),
            ])
            ->filters([
                Filter::make('dia')
                    ->schema([DatePicker::make('fecha')->label('Día de apertura')->native(false)->displayFormat('d/m/Y')])
                    ->query(function (Builder $query, array $data): Builder {
                        $fecha = $data['fecha'] ?? null;

                        if (blank($fecha)) {
                            return $query;
                        }

                        if (! validator(['fecha' => $fecha], ['fecha' => ['date_format:Y-m-d']])->passes()) {
                            return $query->whereKey(0);
                        }

                        return $query->whereDate('fecha_apertura', $fecha);
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                Action::make('verSesiones')
                    ->label('Ver sesiones')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (CajaSesion $record): string => 'Sesiones del '.Carbon::parse($record->getAttribute('dia'))->format('d/m/Y'))
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'caja-modal-window'])
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (CajaSesion $record): View => $this->sesionesDelDia((string) $record->getAttribute('dia'))),
            ])
            ->emptyStateHeading('No hay sesiones para mostrar')
            ->emptyStateDescription('Abrí tu primera caja o elegí otra fecha.');
    }

    public function sesionesDelDia(string $fecha): View
    {
        abort_unless(validator(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']])->passes(), 404);

        return view('filament.negocio.pages.caja.sesiones-del-dia', [
            'fecha' => $fecha,
            'moneda' => $this->moneda(),
        ]);
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
        $sesion = $this->sesionParaCerrar();

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

    /**
     * @return Collection<int, CajaSesion>
     */
    public function pendientes(): Collection
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return collect();
        }

        return CajaSesion::query()
            ->with('sucursal')
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::PendienteArqueo)
            ->orderBy('fecha_apertura')
            ->get();
    }

    public function sesionParaCerrar(): ?CajaSesion
    {
        if ($this->sesionEnConteo !== null) {
            $usuario = auth('tenant')->user();
            $pendiente = CajaSesion::query()
                ->with('sucursal')
                ->whereKey($this->sesionEnConteo)
                ->where('estado_sesion', EstadoCajaSesion::PendienteArqueo)
                ->when($usuario instanceof TenantUser, fn ($query) => $query->where('user_id', $usuario->id))
                ->first();

            if ($pendiente instanceof CajaSesion) {
                return $pendiente;
            }
        }

        return $this->sesionActual();
    }

    public function turnoSinVentas(): bool
    {
        $sesion = $this->sesionParaCerrar();

        if (! $sesion instanceof CajaSesion) {
            return false;
        }

        $arqueo = app(ArqueoCajaSesion::class)->de($sesion);

        return $arqueo['total_cobros'] === '0.00' && $arqueo['total_reembolsos'] === '0.00';
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
        $sesion = $this->sesionParaCerrar();

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
