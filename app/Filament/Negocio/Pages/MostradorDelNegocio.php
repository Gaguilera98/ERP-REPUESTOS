<?php

namespace App\Filament\Negocio\Pages;

use App\Enums\EstadoCajaSesion;
use App\Livewire\Negocio\Venta\Concerns\ManejaMostrador;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use App\Models\Venta;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\WithFileUploads;
use UnitEnum;

class MostradorDelNegocio extends Page
{
    use ManejaMostrador;
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Mostrador';

    protected static string|UnitEnum|null $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Mostrador';

    protected static ?string $slug = 'mostrador';

    protected string $view = 'filament.negocio.pages.mostrador-del-negocio';

    public function getSubheading(): ?string
    {
        return 'Venta de contado · cobro rápido en mostrador';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('misVentas')
                ->label('Mis ventas')
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->color('gray')
                ->authorize(fn (): bool => $this->usuario()->can('viewAny', Venta::class))
                ->disabled(fn (): bool => $this->sucursalId === null)
                ->modalHeading('Mis ventas')
                ->slideOver()
                ->modalWidth('7xl')
                ->stickyModalHeader()
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Cerrar')
                ->modalContent(fn (): View => view('filament.negocio.pages.mostrador.mis-ventas', [
                    'sucursalId' => $this->sucursalId,
                ])),
        ];
    }

    public static function canAccess(): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        $usuario = auth('tenant')->user();

        return $usuario instanceof TenantUser
            && (
                $usuario->can('View:MostradorDelNegocio')
                || $usuario->can('create', Venta::class)
            );
    }

    public function mount(): void
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return;
        }

        $tieneCajaAbierta = CajaSesion::query()
            ->where('user_id', $usuario->id)
            ->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->exists();

        if (! $tieneCajaAbierta) {
            Notification::make()
                ->title('Abrí la caja primero')
                ->body('El mostrador solo se usa con un turno abierto en la sucursal.')
                ->warning()
                ->send();

            $this->redirect(CajaDelNegocio::getUrl());

            return;
        }

        $this->inicializarMostrador();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return $this->datosVistaMostrador();
    }
}
