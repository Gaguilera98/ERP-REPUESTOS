<?php

namespace App\Filament\Negocio\Resources\Almacens\Pages;

use App\Filament\Negocio\Resources\Almacens\AlmacenResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlmacen extends EditRecord
{
    protected static string $resource = AlmacenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
