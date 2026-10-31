<?php

namespace App\Filament\Negocio\Resources\Marcas\Pages;

use App\Filament\Negocio\Resources\Marcas\MarcaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMarcas extends ManageRecords
{
    protected static string $resource = MarcaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
