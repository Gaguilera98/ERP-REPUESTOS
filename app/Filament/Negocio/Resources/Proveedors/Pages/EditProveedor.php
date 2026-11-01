<?php

namespace App\Filament\Negocio\Resources\Proveedors\Pages;

use App\Filament\Negocio\Resources\Proveedors\ProveedorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProveedor extends EditRecord
{
    protected static string $resource = ProveedorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
