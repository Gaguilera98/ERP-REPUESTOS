<?php

namespace App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Pages;

use App\Filament\Negocio\Resources\TipoDocumentoIdentidads\TipoDocumentoIdentidadResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTipoDocumentoIdentidad extends EditRecord
{
    protected static string $resource = TipoDocumentoIdentidadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
