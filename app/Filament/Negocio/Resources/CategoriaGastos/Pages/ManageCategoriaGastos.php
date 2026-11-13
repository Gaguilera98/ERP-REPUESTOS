<?php

namespace App\Filament\Negocio\Resources\CategoriaGastos\Pages;

use App\Filament\Negocio\Resources\CategoriaGastos\CategoriaGastoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCategoriaGastos extends ManageRecords
{
    protected static string $resource = CategoriaGastoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
