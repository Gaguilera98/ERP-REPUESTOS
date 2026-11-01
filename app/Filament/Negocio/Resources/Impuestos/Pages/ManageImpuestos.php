<?php

namespace App\Filament\Negocio\Resources\Impuestos\Pages;

use App\Filament\Negocio\Resources\Impuestos\ImpuestoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageImpuestos extends ManageRecords
{
    protected static string $resource = ImpuestoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
