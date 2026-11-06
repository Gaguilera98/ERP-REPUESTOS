<?php

namespace App\Livewire\Negocio\Caja;

use App\Caja\ArqueoCajaSesion;
use App\Enums\EstadoCajaSesion;
use App\Filament\Negocio\Pages\CajaDelNegocio;
use App\Filament\Negocio\Pages\CajasDelDia;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SesionesDelDia extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public string $fecha;

    #[Locked]
    public ?int $vendedorId = null;

    #[Locked]
    public ?int $sucursalId = null;

    public function mount(string $fecha, ?int $vendedorId = null, ?int $sucursalId = null): void
    {
        abort_unless($vendedorId === null ? CajaDelNegocio::canAccess() : CajasDelDia::canAccess(), 403);
        abort_if(($vendedorId === null) !== ($sucursalId === null), 404);
        abort_unless(validator(['fecha' => $fecha], ['fecha' => ['required', 'date_format:Y-m-d']])->passes(), 404);
        $this->fecha = $fecha;
        $this->vendedorId = $vendedorId;
        $this->sucursalId = $sucursalId;
    }

    public function table(Table $table): Table
    {
        abort_unless($this->vendedorId === null ? CajaDelNegocio::canAccess() : CajasDelDia::canAccess(), 403);

        return $table
            ->heading('Sesiones del día')
            ->extraAttributes(['class' => 'caja-mobile-table'])
            ->query(CajaSesion::query()->with('sucursal')
                ->where('user_id', $this->vendedorId ?? auth('tenant')->id())
                ->when($this->sucursalId !== null, fn ($query) => $query->where('sucursal_id', $this->sucursalId)->where('estado_sesion', '!=', EstadoCajaSesion::Anulada))
                ->whereDate('fecha_apertura', $this->fecha))
            ->defaultSort('fecha_apertura', 'asc')
            ->queryStringIdentifier('sesionesDelDia')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('id')->label('Sesión')->extraCellAttributes(['data-label' => 'Sesión'])->prefix('#'),
                TextColumn::make('sucursal.nombre')->label('Sucursal')->extraCellAttributes(['data-label' => 'Sucursal']),
                TextColumn::make('fecha_apertura')->label('Apertura')->extraCellAttributes(['data-label' => 'Apertura'])->dateTime('d/m/Y H:i'),
                TextColumn::make('fecha_cierre')->label('Cierre')->extraCellAttributes(['data-label' => 'Cierre'])->dateTime('d/m/Y H:i')->placeholder('Sin cerrar'),
                TextColumn::make('estado_sesion')->label('Estado')->extraCellAttributes(['data-label' => 'Estado'])->badge()
                    ->formatStateUsing(fn (EstadoCajaSesion $state): string => $state->etiqueta())
                    ->color(fn (EstadoCajaSesion $state): array => $state->getColor()),
                TextColumn::make('monto_apertura')->label('Fondo inicial')->extraCellAttributes(['data-label' => 'Fondo inicial'])->money(NegocioConfiguracion::codigoMoneda()),
            ])
            ->recordActions([
                Action::make('verSesion')->label('Ver ventas')->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (CajaSesion $record): string => 'Sesión #'.$record->id)
                    ->modalWidth('5xl')
                    ->extraModalWindowAttributes(['class' => 'caja-modal-window'])
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(function (CajaSesion $record): View {
                        $record->loadMissing(['sucursal', 'medios']);

                        return view('filament.negocio.pages.caja.detalle-sesion', [
                            'sesion' => $record,
                            'arqueo' => app(ArqueoCajaSesion::class)->de($record),
                            'moneda' => NegocioConfiguracion::codigoMoneda(),
                            'supervision' => $this->vendedorId !== null,
                        ]);
                    }),
            ])
            ->emptyStateHeading('No hay sesiones en este día');
    }

    public function render(): View
    {
        return view('livewire.negocio.caja.sesiones-del-dia');
    }
}
