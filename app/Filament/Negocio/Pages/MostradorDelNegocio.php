<?php

namespace App\Filament\Negocio\Pages;

use App\Livewire\Negocio\Caja\AccionCambiarProducto;
use App\Livewire\Negocio\Caja\AccionVerDetalleVenta;
use App\Livewire\Negocio\Venta\Concerns\ManejaMostrador;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Tenancy\AccesoSucursales;
use BackedEnum;
use Filament\Actions\Action;
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
            Action::make('cambiarSucursal')
                ->label('Cambiar sucursal')
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->color('gray')
                ->visible(fn (): bool => $this->sucursalId === null
                    || app(AccesoSucursales::class)->disponibles($this->usuario())->count() > 1)
                ->requiresConfirmation(fn (): bool => $this->lineas !== [])
                ->modalHeading('¿Descartar la venta en curso?')
                ->modalDescription('Vaciá el carrito para elegir otra sucursal. Tu turno queda en el local donde lo abriste.')
                ->modalSubmitActionLabel('Descartar y continuar')
                ->modalCancelActionLabel('Seguir vendiendo')
                ->action(function (): void {
                    $this->vaciarCarrito();
                    $this->redirect(ElegirSucursal::getUrl(['volver' => 'mostrador']));
                }),
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

    public function detalleUltimaVentaAction(): Action
    {
        $venta = $this->ultimaVentaId === null ? null : Venta::query()
            ->whereKey($this->ultimaVentaId)
            ->where('user_id', $this->usuario()->id)
            ->first();

        return app(AccionVerDetalleVenta::class)->crear('detalleUltimaVenta')
            ->record($venta)
            ->extraModalFooterActions(fn (Venta $record): array => [app(AccionCambiarProducto::class)->para($record)])
            ->disabled($venta === null);
    }

    public function mount(): void
    {
        $usuario = auth('tenant')->user();

        if (! $usuario instanceof TenantUser) {
            return;
        }

        $this->inicializarMostrador();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            ...$this->datosVistaMostrador(),
            'puedeVerUltimaVenta' => $this->ultimaVentaId !== null && $this->usuario()->can('View:Venta'),
        ];
    }
}
