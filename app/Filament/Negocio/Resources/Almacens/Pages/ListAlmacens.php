<?php

namespace App\Filament\Negocio\Resources\Almacens\Pages;

use App\Filament\Negocio\Resources\Almacens\AlmacenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlmacens extends ListRecords
{
    protected static string $resource = AlmacenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
