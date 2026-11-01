<?php

namespace App\Filament\Negocio\Resources\OrdenCompras\Pages;

use App\Filament\Negocio\Resources\OrdenCompras\OrdenCompraResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrdenCompras extends ListRecords
{
    protected static string $resource = OrdenCompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
