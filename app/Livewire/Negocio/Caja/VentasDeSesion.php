<?php

namespace App\Livewire\Negocio\Caja;

use App\Enums\CondicionVenta;
use App\Enums\EstadoVenta;
use App\Enums\TipoComprobante;
use App\Filament\Negocio\Pages\CajaDelNegocio;
use App\Filament\Negocio\Pages\CajasDelDia;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use App\Models\PagoAplicacion;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\AnularVenta;
use App\Ventas\CompletarVentaContado;
use App\Ventas\CorregirVenta;
use App\Ventas\GestionarReservas;
use App\Ventas\SaldoAFavorCliente;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VentasDeSesion extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public ?int $sesionId = null;

    #[Locked]
    public bool $supervision = false;

    #[Locked]
    public ?int $sucursalMostradorId = null;

    public function mount(?int $sesionId = null, bool $supervision = false, ?int $sucursalMostradorId = null): void
    {
        abort_if(($sesionId === null) === ($sucursalMostradorId === null) || ($supervision && $sucursalMostradorId !== null), 404);
        $this->sesionId = $sesionId;
        $this->supervision = $supervision;
        $this->sucursalMostradorId = $sucursalMostradorId;
        $this->autorizarConsulta();

        if ($sesionId !== null) {
            CajaSesion::query()->when(! $supervision, fn (Builder $query) => $query->where('user_id', auth('tenant')->id()))->findOrFail($sesionId);
        }
    }

    public function table(Table $table): Table
    {
        $this->autorizarConsulta();
        app(GestionarReservas::class)->procesarVencidas();
        $enMostrador = $this->sucursalMostradorId !== null;

        return $table
            ->heading($enMostrador ? 'Ventas de la sucursal activa' : 'Ventas de esta sesión')
            ->extraAttributes(['class' => 'caja-mobile-table'])
            ->query($enMostrador
                ? Venta::query()->where('user_id', auth('tenant')->id())->where('sucursal_id', $this->sucursalMostradorId)
                : Venta::query()->where(function (Builder $ventas): void {
                    $sesion = fn (Builder $query): Builder => $query->whereKey($this->sesionId)
                        ->when(! $this->supervision, fn (Builder $query): Builder => $query->where('user_id', auth('tenant')->id()));

                    $ventas->whereHas('cajaSesion', $sesion)->orWhereHas('aplicacionesPago', fn (Builder $aplicaciones): Builder => $aplicaciones
                        ->where('es_saldo_favor', false)->whereHas('pago.cajaSesion', $sesion));
                }))
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ultimaCorreccion'))
            ->queryStringIdentifier($enMostrador ? 'misVentasMostrador' : 'ventasDeSesion')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->searchPlaceholder($enMostrador ? 'Buscar por código o cliente' : 'Buscar por código de venta')
            ->columns([
                TextColumn::make('codigo')->label('Venta')->searchable()->extraCellAttributes(['data-label' => 'Venta']),
                TextColumn::make('created_at')->label('Fecha y hora')->extraCellAttributes(['data-label' => 'Fecha y hora'])->dateTime('d/m/Y H:i'),
                TextColumn::make('cliente.razon_social')->label('Cliente')->placeholder('Consumidor final')->searchable()->visible($enMostrador)->extraCellAttributes(['data-label' => 'Cliente']),
                TextColumn::make('condicion')->label('Condición')->formatStateUsing(fn (CondicionVenta $state): string => $state->etiqueta())->visible($enMostrador)->extraCellAttributes(['data-label' => 'Condición']),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoVenta $state): string => $state->etiqueta())
                    ->color(fn (EstadoVenta $state): array => $this->colorEstado($state))
                    ->extraCellAttributes(['data-label' => 'Estado']),
                TextColumn::make('total')->label('Total')->extraCellAttributes(['data-label' => 'Total'])
                    ->state(fn (Venta $record): string => $enMostrador ? $record->totalVigente() : $record->total)
                    ->description(fn (Venta $record): ?string => $record->ultimaCorreccion ? 'Con corrección' : null)
                    ->money(NegocioConfiguracion::codigoMoneda()),
            ])
            ->filters($enMostrador ? [
                Filter::make('dia')
                    ->schema([DatePicker::make('fecha')->label('Fecha de venta')->native(false)->displayFormat('d/m/Y')->default(fn (): string => now()->toDateString())])
                    ->query(function (Builder $query, array $data): Builder {
                        $fecha = $data['fecha'] ?? now()->toDateString();

                        if (! validator(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d,Y-m-d H:i:s']])->passes()) {
                            return $query->whereKey(0);
                        }

                        return $query->whereDate('created_at', substr($fecha, 0, 10));
                    }),
                SelectFilter::make('estado')->label('Estado')->options([
                    EstadoVenta::Reserva->value => 'Reserva',
                    EstadoVenta::Vencida->value => 'Vencida',
                    EstadoVenta::Completada->value => 'Completada',
                    EstadoVenta::Anulada->value => 'Anulada',
                ]),
                SelectFilter::make('condicion')
                    ->label('Operación')
                    ->placeholder('Todas')
                    ->options([
                        CondicionVenta::Contado->value => CondicionVenta::Contado->etiqueta(),
                        CondicionVenta::Credito->value => CondicionVenta::Credito->etiqueta(),
                    ]),
            ] : [], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                Action::make('verDetalle')
                    ->label('Ver detalle')
                    ->icon(Heroicon::OutlinedEye)
                    ->authorize(fn (Venta $record): bool => (bool) auth('tenant')->user()?->can('view', $record))
                    ->modalHeading(fn (Venta $record): string => $record->tipo_comprobante->etiqueta().' · '.$record->codigo)
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'caja-modal-window'])
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->extraModalFooterActions(fn (Venta $record): array => [
                        ...$this->accionesAbono($record), $this->accionReembolsarSena($record), $this->accionEntregarReserva($record), $this->accionAmpliarReserva($record), $this->accionCancelarReserva($record), app(AccionCorregirVenta::class)->para($record), $this->accionAnular($record),
                    ])
                    ->modalContent(function (Venta $record): View {
                        abort_unless(auth('tenant')->user()?->can('view', $record), 403);

                        $record->loadMissing([
                            'cliente',
                            'usuario',
                            'anuladaPor',
                            'correcciones.usuario',
                            'ultimaCorreccion',
                            'sucursal',
                            'almacen',
                            'detalles.variante.productoMaestro.unidadBase',
                            'detalles.unidad',
                            'aplicacionesPago.pago.registradoPor',
                            'aplicacionesPago.usos',
                        ]);
                        $reservaCancelada = $record->estado === EstadoVenta::Anulada && $record->aplicacionesPago->contains(
                            fn (PagoAplicacion $aplicacion): bool => $aplicacion->usos->contains(
                                fn (PagoAplicacion $uso): bool => $uso->venta_id === null && (int) $uso->reserva_saldo_origen_id === $record->id,
                            ),
                        );

                        return view('filament.negocio.pages.caja.detalle-venta', [
                            'venta' => $record,
                            'ampliacionesReserva' => DB::table('ampliaciones_reserva as ampliacion')
                                ->join('users as usuario', function ($join): void {
                                    $join->on('usuario.id', '=', 'ampliacion.user_id')->on('usuario.tenant_id', '=', 'ampliacion.tenant_id');
                                })->where('ampliacion.tenant_id', $record->tenant_id)->where('ampliacion.venta_id', $record->id)
                                ->orderByDesc('ampliacion.id')->get(['ampliacion.*', 'usuario.name as usuario_nombre']),
                            'moneda' => NegocioConfiguracion::codigoMoneda(),
                            'saldoSenaDisponible' => $record->estado === EstadoVenta::Vencida || $reservaCancelada ? app(SaldoAFavorCliente::class)->disponibleDeReserva($record) : null,
                            'saldoPendiente' => $record->condicion === CondicionVenta::Credito || $record->estado === EstadoVenta::Reserva ? $record->saldoPendiente() : null,
                            'colorEstado' => $this->colorEstado($record->estado),
                        ]);
                    }),
            ])
            ->emptyStateHeading($enMostrador ? 'No hay ventas para esta consulta' : 'Esta sesión no tiene ventas registradas');
    }

    /** @return array<int, string> */
    private function colorEstado(EstadoVenta $estado): array
    {
        return match ($estado) {
            EstadoVenta::Completada => Color::Emerald,
            EstadoVenta::Anulada, EstadoVenta::Vencida => Color::Red,
            EstadoVenta::Pendiente => Color::Amber,
            EstadoVenta::Reserva => Color::Blue,
        };
    }

    private function accionAnular(Venta $venta): Action
    {
        return Action::make('anularVenta')
            ->label('Anular venta')
            ->color('danger')
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('anular', $venta))
            ->visible(fn (): bool => $venta->estaCompletada() && $venta->tipo_comprobante === TipoComprobante::NotaVenta)
            ->modalHeading('Anular venta · '.$venta->codigo)
            ->modalDescription(function () use ($venta): string {
                $moneda = NegocioConfiguracion::codigoMoneda();
                $saldo = $venta->saldoPendiente();
                $cobrado = $venta->cobradoNeto();
                $restituir = collect(app(CorregirVenta::class)->distribuirReembolso($venta, $cobrado))
                    ->filter(fn (array $pago): bool => $pago['saldo_favor'])
                    ->reduce(fn (string $total, array $pago): string => bcadd($total, $pago['monto'], 2), '0.00');
                $reembolsar = bcsub($cobrado, $restituir, 2);

                return "Se repondrá el stock en el almacén original. Reembolso de dinero: {$moneda} {$reembolsar}. Restituir saldo a favor: {$moneda} {$restituir}. Deuda a cancelar: {$moneda} {$saldo}. Confirmá después de devolver el dinero por sus métodos originales.";
            })
            ->modalSubmitActionLabel('Confirmar anulación')
            ->schema([
                Hidden::make('cobrado')->default(fn (): string => $venta->cobradoNeto()),
                Textarea::make('motivo')->label('Motivo de la anulación')->required()->maxLength(500),
            ])
            ->action(function (array $data, Action $action, AnularVenta $anular) use ($venta): void {
                $usuario = auth('tenant')->user();
                abort_unless($usuario instanceof TenantUser, 403);

                try {
                    $anular->anular($venta, (string) $data['motivo'], (string) $data['cobrado'], $usuario);
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudo anular la venta')
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()->send();
                    $action->halt();

                    return;
                }

                $venta->refresh();
                Notification::make()->title('Venta anulada')->success()->send();
            });
    }

    private function accionEntregarReserva(Venta $venta): Action
    {
        return Action::make('entregarReserva')->label('Cobrar saldo y entregar')
            ->visible(fn (): bool => $venta->estado === EstadoVenta::Reserva)
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('create', Venta::class))
            ->modalHeading('Entregar reserva · '.$venta->codigo)
            ->modalDescription(fn (): string => 'Cobro del saldo en efectivo: '.NegocioConfiguracion::codigoMoneda().' '.$venta->saldoPendiente().'. Confirmá al entregar los productos.')
            ->schema([Hidden::make('saldo')->default(fn (): string => $venta->saldoPendiente())])
            ->requiresConfirmation()
            ->action(function (array $data, Action $action) use ($venta): void {
                try {
                    app(GestionarReservas::class)->entregar($venta, $data['saldo'], auth('tenant')->user());
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudo entregar la reserva')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }
                $venta->refresh();
                Notification::make()->title('Reserva entregada')->success()->send();
            });
    }

    private function accionAmpliarReserva(Venta $venta): Action
    {
        return Action::make('ampliarReserva')->label('Ampliar reserva')->color('gray')->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (): bool => $venta->estado === EstadoVenta::Reserva && $venta->vence_en->isFuture())
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('Ampliar:Reserva'))
            ->modalHeading('Ampliar reserva · '.$venta->codigo)
            ->modalDescription(fn (): string => 'Vencimiento actual: '.$venta->vence_en->format('d/m/Y H:i').'. Elegí una fecha posterior y explicá el motivo.')
            ->schema([
                Hidden::make('actual')->default(fn (): string => $venta->vence_en->format('Y-m-d H:i:s')),
                DateTimePicker::make('vencimiento')->label('Nuevo vencimiento')->seconds(false)
                    ->default(fn (): string => $venta->vence_en->copy()->addDay()->setTime(23, 59)->format('Y-m-d H:i:s'))
                    ->required(),
                Textarea::make('motivo')->label('Motivo de la ampliación')->trim()->required()->maxLength(500),
            ])
            ->modalSubmitActionLabel('Confirmar ampliación')
            ->action(function (array $data, Action $action, GestionarReservas $reservas) use ($venta): void {
                $usuario = auth('tenant')->user();
                abort_unless($usuario instanceof TenantUser, 403);
                try {
                    $reservas->ampliar($venta, $data['vencimiento'], $data['actual'], $data['motivo'], $usuario);
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudo ampliar la reserva')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }
                $venta->refresh();
                Notification::make()->title('Reserva ampliada')->success()->send();
            });
    }

    private function accionCancelarReserva(Venta $venta): Action
    {
        return Action::make('cancelarReserva')
            ->label('Cancelar reserva')->color('danger')->icon(Heroicon::OutlinedXCircle)
            ->visible(fn (): bool => $venta->estado === EstadoVenta::Reserva)
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('anular', $venta))
            ->modalHeading('Cancelar reserva · '.$venta->codigo)
            ->modalDescription('Los productos quedarán disponibles y la seña pasará al saldo a favor del cliente. Esta acción no devuelve dinero. Después podrás registrar el reembolso desde el detalle, si las condiciones de la reserva y tus permisos lo permiten.')
            ->modalSubmitActionLabel('Confirmar cancelación')
            ->schema([Textarea::make('motivo')->label('Motivo de la cancelación')->trim()->required()->maxLength(500)])
            ->action(function (array $data, Action $action, GestionarReservas $reservas) use ($venta): void {
                $usuario = auth('tenant')->user();
                abort_unless($usuario instanceof TenantUser, 403);
                try {
                    $reservas->cancelar($venta, $data['motivo'], $usuario);
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudo cancelar la reserva')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }
                $venta->refresh();
                Notification::make()->title('Reserva cancelada')->body('Los productos se liberaron y la seña quedó a favor del cliente.')->success()->send();
            });
    }

    private function autorizarConsulta(): void
    {
        if ($this->sucursalMostradorId !== null) {
            abort_unless(MostradorDelNegocio::canAccess() && auth('tenant')->user()?->can('viewAny', Venta::class), 403);

            return;
        }

        abort_unless($this->supervision ? CajasDelDia::canAccess() : CajaDelNegocio::canAccess(), 403);
    }

    private function accionReembolsarSena(Venta $venta): Action
    {
        return Action::make('reembolsarSena')->label('Reembolsar seña')
            ->color('warning')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (): bool => in_array($venta->estado, [EstadoVenta::Vencida, EstadoVenta::Anulada], true) && $venta->sena_reembolsable
                && bccomp(app(SaldoAFavorCliente::class)->disponibleDeReserva($venta), '0', 2) > 0)
            ->authorize(fn (): bool => (bool) auth('tenant')->user()?->can('Reembolsar:Sena'))
            ->modalHeading('Reembolsar seña · '.$venta->codigo)
            ->modalDescription(fn (): string => 'Disponible de esta seña: '.NegocioConfiguracion::codigoMoneda().' '.app(SaldoAFavorCliente::class)->disponibleDeReserva($venta).'. Registrá la devolución realizada por los métodos de pago originales. Se descuenta de tu caja de hoy.')
            ->schema([
                Hidden::make('disponible')->default(fn (): string => app(SaldoAFavorCliente::class)->disponibleDeReserva($venta)),
                TextInput::make('monto')->label('Importe a devolver')->prefix(NegocioConfiguracion::codigoMoneda())
                    ->inputMode('decimal')->required()->rules(['regex:/^\d{1,8}(\.\d{1,2})?$/', 'numeric', 'gt:0']),
                Textarea::make('motivo')->label('Motivo del reembolso')->trim()->required()->maxLength(500),
            ])
            ->modalSubmitActionLabel('Registrar reembolso')
            ->action(function (array $data, Action $action) use ($venta): void {
                try {
                    app(SaldoAFavorCliente::class)->reembolsar($venta, $data['monto'], $data['motivo'], auth('tenant')->user(), $data['disponible']);
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudo reembolsar')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }
                $venta->refresh();
                Notification::make()->title('Reembolso registrado')->success()->send();
            });
    }

    /** @return list<Action> */
    private function accionesAbono(Venta $venta): array
    {
        if ($this->sucursalMostradorId === null || $venta->condicion !== CondicionVenta::Credito || ! $venta->estaCompletada()) {
            return [];
        }

        return [
            Action::make('registrarAbono')
                ->label('Registrar abono')
                ->icon(Heroicon::OutlinedBanknotes)
                ->authorize(fn (): bool => $this->puedeAbonar($venta))
                ->visible(fn (): bool => bccomp($venta->saldoPendiente(), '0', 2) > 0)
                ->modalHeading('Abono en efectivo · '.$venta->codigo)
                ->modalDescription(fn (): string => 'Saldo pendiente: '.NegocioConfiguracion::codigoMoneda().' '.$venta->saldoPendiente())
                ->modalSubmitActionLabel('Confirmar abono')
                ->schema([
                    TextInput::make('monto')
                        ->label('Importe del abono')
                        ->prefix(NegocioConfiguracion::codigoMoneda())
                        ->numeric()
                        ->rules(['regex:/^\d{1,8}(\.\d{1,2})?$/', 'gt:0'])
                        ->required(),
                ])
                ->action(function (array $data, Action $action, CompletarVentaContado $ventas) use ($venta): void {
                    abort_unless($this->puedeAbonar($venta), 403);
                    $usuario = auth('tenant')->user();
                    abort_unless($usuario instanceof TenantUser, 403);

                    try {
                        $saldo = $ventas->abonarEnEfectivo($venta->codigo, (int) $venta->sucursal_id, (string) $data['monto'], $usuario);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('No se pudo registrar el abono')
                            ->body(collect($exception->errors())->flatten()->first())
                            ->danger()->send();
                        $action->halt();

                        return;
                    }

                    $venta->unsetRelation('aplicacionesPago');
                    Notification::make()->title('Abono registrado')
                        ->body('Saldo pendiente: '.NegocioConfiguracion::codigoMoneda().' '.$saldo)
                        ->success()->send();
                }),
        ];
    }

    private function puedeAbonar(Venta $venta): bool
    {
        $usuario = auth('tenant')->user();

        return $this->sucursalMostradorId !== null
            && $usuario instanceof TenantUser
            && (int) $venta->user_id === (int) $usuario->id
            && (int) $venta->sucursal_id === $this->sucursalMostradorId
            && $usuario->can('create', Venta::class)
            && $usuario->can('view', $venta);
    }

    public function render(): View
    {
        return view('livewire.negocio.caja.ventas-de-sesion');
    }
}
