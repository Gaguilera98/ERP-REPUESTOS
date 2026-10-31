<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Catalogo\VariantesProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\Concerns\GuardaVariantesDelProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\ProductoMaestro;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductoMaestro extends EditRecord
{
    use GuardaVariantesDelProducto;

    protected static string $resource = ProductoMaestroResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
