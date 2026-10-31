<?php

namespace App\Filament\Negocio\Resources\UnidadMedidas\Pages;

use App\Filament\Negocio\Resources\UnidadMedidas\UnidadMedidaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUnidadMedidas extends ManageRecords
{
    protected static string $resource = UnidadMedidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
