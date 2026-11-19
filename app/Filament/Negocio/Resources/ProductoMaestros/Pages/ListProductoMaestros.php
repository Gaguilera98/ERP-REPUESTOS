<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductoMaestros extends ListRecords
{
    protected static string $resource = ProductoMaestroResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importar')->label('Importar productos')->color('gray')->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => ImportarProductos::canAccess())
                ->url(ProductoMaestroResource::getUrl('importar')),
            CreateAction::make(),
        ];
    }
}
