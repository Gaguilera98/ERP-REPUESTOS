<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductoMaestros extends ListRecords
{
    protected static string $resource = ProductoMaestroResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
