<?php

namespace App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Pages;

use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\TipoDocumentoIdentidadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTipoDocumentoIdentidads extends ListRecords
{
    protected static string $resource = TipoDocumentoIdentidadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo tipo'),
        ];
    }
}
