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
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
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

    /** @var array<int, CajaSesion|null> */
    private array $turnosAnteriores = [];

    /** @var array<int, array<string, mixed>> */
    private array $arqueos = [];

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
                Grid::make(1)
                    ->extraAttributes(['class' => 'caja-declaracion-medios'])
                    ->visible(fn (): bool => $this->sesionActual() instanceof CajaSesion)
                    ->schema($this->camposConteo($moneda, fn (): ?array => $this->arqueoTurno())),
            ])
            ->statePath('data');
    }

    /** @return list<TextInput|Textarea> */
    private function camposConteo(string $moneda, Closure $arqueo): array
    {
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
                ->hintIcon(function (Get $get) use ($metodo, $arqueo): ?Heroicon {
                    return $this->medioTieneDiferencia($arqueo(), $metodo->value, $get('medios_declarados'))
                        ? Heroicon::ExclamationCircle
                        : null;
                })
                ->hintColor(function (Get $get) use ($metodo, $arqueo): ?string {
                    return $this->medioTieneDiferencia($arqueo(), $metodo->value, $get('medios_declarados'))
                        ? 'danger'
                        : null;
                })
                ->extraFieldWrapperAttributes(function (Get $get) use ($metodo, $arqueo): array {
                    if (! $this->medioTieneDiferencia($arqueo(), $metodo->value, $get('medios_declarados'))) {
                        return [];
                    }

                    return [
                        'class' => 'fi-fo-field-wrp-error',
                    ];
                })
                ->helperText(function (Get $get) use ($metodo, $moneda, $arqueo): string|HtmlString {
                    $esperado = $this->esperadoDeMedio($arqueo(), $metodo->value);

                    if ($esperado === null) {
                        return '';
                    }

                    $declarados = $get('medios_declarados');
                    $dif = $this->diferenciaDeMedio(
                        $arqueo(),
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

        return [
            ...$camposMedios,
            Textarea::make('justificacion_diferencia')
                ->label('Justificación de la diferencia')
                ->placeholder('Ingresá la justificación (obligatoria)...')
                ->rows(3)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->hayDiferenciaEnMedios($arqueo(), $get('medios_declarados')))
                ->required(fn (Get $get): bool => $this->hayDiferenciaEnMedios($arqueo(), $get('medios_declarados')))
                ->helperText('La justificación es obligatoria para cerrar con descuadre.')
                ->live(debounce: 300),
        ];
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

    public function cerrar(): void
    {
        $sesion = $this->sesionActual();
        abort_unless($sesion instanceof CajaSesion && auth('tenant')->user()?->can('update', $sesion), 403);

        $this->cerrarTurno($sesion, $this->form->getState());
        $this->confirmandoCierre = false;
        $this->rellenarFormulario();
    }

    public function contarTurnoAnteriorAction(): Action
    {
        return Action::make('contarTurnoAnterior')
            ->record(fn (array $arguments): ?CajaSesion => $this->turnoAnteriorPendiente($arguments['sesionId'] ?? null))
            ->authorize(fn (?CajaSesion $record): bool => $record !== null && (bool) auth('tenant')->user()?->can('update', $record))
            ->slideOver()
            ->modalWidth('3xl')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->closeModalByClickingAway(false)
            ->modalHeading(fn (CajaSesion $record): string => 'Turno anterior · '.$record->fecha_apertura->format('d/m/Y'))
            ->modalDescription(fn (CajaSesion $record): string => 'Sesión #'.$record->id.' · '.$record->sucursal?->nombre.' · Apertura '.$record->fecha_apertura->format('H:i'))
            ->modalCancelActionLabel('Salir del conteo')
            ->modalSubmitActionLabel(fn (CajaSesion $record): string => $this->sinMovimientos($this->arqueoDe($record)) ? 'Anular turno vacío' : 'Confirmar cierre')
            ->modalContent(fn (CajaSesion $record): View => view('filament.negocio.pages.caja.resumen-turno', [
                'sesion' => $record,
                'arqueo' => $this->arqueoDe($record),
                'moneda' => $this->moneda(),
            ]))
            ->fillForm(fn (CajaSesion $record): array => $this->declaracionInicial($this->arqueoDe($record)))
            ->steps(function (?CajaSesion $record): array {
                if ($record === null) {
                    return [];
                }

                $moneda = $this->moneda();
                $arqueo = $this->arqueoDe($record);
                $confirmacion = $this->sinMovimientos($arqueo)
                    ? [Text::make('Este turno quedó sin cobros ni reembolsos y se registrará como anulado.')]
                    : array_map(
                        fn (MetodoPago $metodo): Text => Text::make(fn (Get $get): string => $metodo->etiqueta().' · '.$moneda.' '.$get('medios_declarados.'.$metodo->value)),
                        MetodoPago::cases(),
                    );

                return [
                    Step::make('Conteo')->schema($this->camposConteo($moneda, fn (): array => $arqueo)),
                    Step::make('Confirmación')->schema($confirmacion),
                ];
            })
            ->modifyWizardUsing(fn (Wizard $wizard): Wizard => $wizard->nextAction(fn (Action $action): Action => $action->label('Revisar cierre')))
            ->action(function (CajaSesion $record, array $data): void {
                $this->cerrarTurno($record, $data);
            });
    }

    public function contar(int $sesionId): void
    {
        $this->mountAction('contarTurnoAnterior', ['sesionId' => $sesionId]);
    }

    private function turnoAnteriorPendiente(mixed $sesionId): ?CajaSesion
    {
        if (! is_int($sesionId) || $sesionId < 1 || auth('tenant')->id() === null) {
            return null;
        }

        if (! array_key_exists($sesionId, $this->turnosAnteriores)) {
            $this->turnosAnteriores[$sesionId] = CajaSesion::query()
                ->with('sucursal')
                ->whereKey($sesionId)
                ->where('user_id', auth('tenant')->id())
                ->where('estado_sesion', EstadoCajaSesion::PendienteArqueo)
                ->first();
        }

        return $this->turnosAnteriores[$sesionId];
    }

    /** @param array<string, mixed> $data */
    private function cerrarTurno(CajaSesion $sesion, array $data): void
    {
        $usuario = auth('tenant')->user();
        abort_unless($usuario instanceof TenantUser && $usuario->can('update', $sesion), 403);

        $cerrada = app(CerrarCajaSesion::class)->cerrar($sesion, $data, $usuario);
        $anulada = $cerrada->estado_sesion === EstadoCajaSesion::Anulada;

        Notification::make()
            ->title($anulada ? 'Turno anulado' : 'Caja cerrada')
            ->body($anulada
                ? 'No tuvo ventas, así que no entra en el día.'
                : 'El arqueo del turno se registró correctamente.')
            ->success()
            ->send();
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
        $sesion = $this->sesionActual();

        if (! $sesion instanceof CajaSesion) {
            return null;
        }

        return $this->arqueoDe($sesion);
    }

    /** @return array<string, mixed> */
    private function arqueoDe(CajaSesion $sesion): array
    {
        return $this->arqueos[$sesion->id] ??= app(ArqueoCajaSesion::class)->de($sesion);
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

    public function turnoSinVentas(): bool
    {
        $arqueo = $this->arqueoTurno();

        return $arqueo !== null && $this->sinMovimientos($arqueo);
    }

    /** @param array<string, mixed> $arqueo */
    private function sinMovimientos(array $arqueo): bool
    {
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
        return $this->diferenciasDeConteo($this->arqueoTurno(), $declarados);
    }

    /**
     * @param  array<string, mixed>|null  $arqueo
     * @param  array<string, mixed>  $declarados
     * @return array<string, string>
     */
    private function diferenciasDeConteo(?array $arqueo, array $declarados): array
    {
        $salida = [];

        if ($arqueo === null) {
            return $salida;
        }

        foreach ($arqueo['por_metodo'] as $fila) {
            $dif = $this->diferenciaDeMedio($arqueo, $fila['metodo'], $declarados[$fila['metodo']] ?? null);
            $salida[$fila['metodo']] = $dif ?? '0.00';
        }

        return $salida;
    }

    private function rellenarFormulario(): void
    {
        $arqueo = $this->arqueoTurno();

        if ($arqueo !== null) {
            $this->form->fill($this->declaracionInicial($arqueo));

            return;
        }

        $this->form->fill([
            'sucursal_id' => Sucursal::query()->where('is_active', true)->orderBy('nombre')->value('id'),
            'monto_apertura' => '0.00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arqueo
     * @return array{medios_declarados: array<string, string>, justificacion_diferencia: null}
     */
    private function declaracionInicial(array $arqueo): array
    {
        return [
            'medios_declarados' => array_column($arqueo['por_metodo'], 'esperado', 'metodo'),
            'justificacion_diferencia' => null,
        ];
    }

    /** @param array<string, mixed>|null $arqueo */
    private function esperadoDeMedio(?array $arqueo, string $metodo): ?string
    {
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

    /** @param array<string, mixed>|null $arqueo */
    private function diferenciaDeMedio(?array $arqueo, string $metodo, mixed $valor): ?string
    {
        $esperado = $this->esperadoDeMedio($arqueo, $metodo);

        if ($esperado === null || ! is_numeric($valor)) {
            return null;
        }

        return Montos::dos(bcsub(
            Montos::dos((string) $valor),
            $esperado,
            4,
        ));
    }

    /** @param array<string, mixed>|null $arqueo */
    private function medioTieneDiferencia(?array $arqueo, string $metodo, mixed $declarados): bool
    {
        if (! is_array($declarados)) {
            return false;
        }

        $dif = $this->diferenciaDeMedio($arqueo, $metodo, $declarados[$metodo] ?? null);

        return $dif !== null && $dif !== '0.00';
    }

    /** @param array<string, mixed>|null $arqueo */
    private function hayDiferenciaEnMedios(?array $arqueo, mixed $declarados): bool
    {
        if (! is_array($declarados)) {
            return false;
        }

        foreach ($this->diferenciasDeConteo($arqueo, $declarados) as $diferencia) {
            if ($diferencia !== '0.00') {
                return true;
            }
        }

        return false;
    }
}
