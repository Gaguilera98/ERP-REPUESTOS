<?php

namespace App\Filament\Negocio\Resources\Sucursals\Pages;

use App\Filament\Negocio\Resources\Sucursals\SucursalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSucursals extends ListRecords
{
    protected static string $resource = SucursalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
