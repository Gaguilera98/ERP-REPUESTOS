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
use App\Models\TenantUser;
use App\Models\Venta;
use App\Ventas\AnularVenta;
use App\Ventas\CompletarVentaContado;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
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
        $enMostrador = $this->sucursalMostradorId !== null;

        return $table
            ->heading($enMostrador ? 'Ventas de la sucursal activa' : 'Ventas de esta sesión')
            ->extraAttributes(['class' => 'caja-mobile-table'])
            ->query($enMostrador
                ? Venta::query()->where('user_id', auth('tenant')->id())->where('sucursal_id', $this->sucursalMostradorId)
                : Venta::query()->where(function (Builder $ventas): void {
                    $sesion = fn (Builder $query): Builder => $query->whereKey($this->sesionId)
                        ->when(! $this->supervision, fn (Builder $query): Builder => $query->where('user_id', auth('tenant')->id()));

                    $ventas->whereHas('cajaSesion', $sesion)->orWhereHas('aplicacionesPago.pago.cajaSesion', $sesion);
                }))
            ->defaultSort('created_at', 'desc')
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
                TextColumn::make('total')->label('Total')->extraCellAttributes(['data-label' => 'Total'])->money(NegocioConfiguracion::codigoMoneda()),
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
                    ->extraModalFooterActions(fn (Venta $record): array => [...$this->accionesAbono($record), $this->accionAnular($record)])
                    ->modalContent(function (Venta $record): View {
                        abort_unless(auth('tenant')->user()?->can('view', $record), 403);

                        $record->loadMissing([
                            'cliente',
                            'usuario',
                            'anuladaPor',
                            'sucursal',
                            'almacen',
                            'detalles.variante.productoMaestro.unidadBase',
                            'detalles.unidad',
                            'aplicacionesPago.pago',
                        ]);

                        return view('filament.negocio.pages.caja.detalle-venta', [
                            'venta' => $record,
                            'moneda' => NegocioConfiguracion::codigoMoneda(),
                            'saldoPendiente' => $record->condicion === CondicionVenta::Credito ? $record->saldoPendiente() : null,
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
                $cobrado = bcsub((string) $venta->total, $saldo, 2);

                return "Se repondrá el stock en el almacén original. Reembolso a registrar: {$moneda} {$cobrado}. Saldo a cancelar: {$moneda} {$saldo}. Confirmá después de devolver los cobros por sus métodos originales.";
            })
            ->modalSubmitActionLabel('Confirmar anulación')
            ->schema([
                Hidden::make('cobrado')->default(fn (): string => bcsub((string) $venta->total, $venta->saldoPendiente(), 2)),
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

    private function autorizarConsulta(): void
    {
        if ($this->sucursalMostradorId !== null) {
            abort_unless(MostradorDelNegocio::canAccess() && auth('tenant')->user()?->can('viewAny', Venta::class), 403);

            return;
        }

        abort_unless($this->supervision ? CajasDelDia::canAccess() : CajaDelNegocio::canAccess(), 403);
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
