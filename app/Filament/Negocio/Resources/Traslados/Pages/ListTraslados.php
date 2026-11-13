<?php

namespace App\Filament\Negocio\Resources\Traslados\Pages;

use App\Filament\Negocio\Resources\Traslados\TrasladoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTraslados extends ListRecords
{
    protected static string $resource = TrasladoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo traslado'),
        ];
    }
}
