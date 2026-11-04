<?php

namespace App\Livewire\Negocio\Caja;

use App\Filament\Negocio\Pages\CajaDelNegocio;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use App\Models\Venta;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

class VentasDeSesion extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $sesionId;

    public function mount(int $sesionId): void
    {
        abort_unless(CajaDelNegocio::canAccess(), 403);
        CajaSesion::query()->where('user_id', auth('tenant')->id())->findOrFail($sesionId);
        $this->sesionId = $sesionId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ventas de esta sesión')
            ->extraAttributes(['class' => 'caja-mobile-table'])
            ->query(Venta::query()->whereHas('aplicacionesPago.pago', function (Builder $query): void {
                $query->where('caja_sesion_id', $this->sesionId)
                    ->whereHas('cajaSesion', fn (Builder $sesiones): Builder => $sesiones->where('user_id', auth('tenant')->id()));
            }))
            ->defaultSort('created_at', 'desc')
            ->queryStringIdentifier('ventasDeSesion')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('codigo')->label('Venta')->extraCellAttributes(['data-label' => 'Venta']),
                TextColumn::make('created_at')->label('Fecha y hora')->extraCellAttributes(['data-label' => 'Fecha y hora'])->dateTime('d/m/Y H:i'),
                TextColumn::make('total')->label('Total')->extraCellAttributes(['data-label' => 'Total'])->money(NegocioConfiguracion::codigoMoneda()),
            ])
            ->emptyStateHeading('Esta sesión no tiene ventas registradas');
    }

    public function render(): View
    {
        return view('livewire.negocio.caja.ventas-de-sesion');
    }
}
