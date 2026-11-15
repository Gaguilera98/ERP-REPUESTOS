<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Catalogo\VariantesProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\Concerns\GuardaVariantesDelProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\ProductoMaestro;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditProductoMaestro extends EditRecord
{
    use GuardaVariantesDelProducto;

    protected static string $resource = ProductoMaestroResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kardex')->label('Ver kardex')->icon(Heroicon::OutlinedClock)->color('gray')
                ->authorize(fn (): bool => ProductoMaestroResource::canView($this->getRecord())
                    && app(AccesoSucursales::class)->puedeVerStock(auth('tenant')->user()))
                ->url(fn (): string => $this->getResourceUrl('kardex')),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $producto = $this->getRecord();

        if (! $producto instanceof ProductoMaestro) {
            return $data;
        }

        return [
            ...$data,
            ...app(VariantesProducto::class)->estadoDesde($producto),
        ];
    }
}
