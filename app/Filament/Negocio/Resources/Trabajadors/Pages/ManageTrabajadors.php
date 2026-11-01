<?php

namespace App\Filament\Negocio\Resources\Trabajadors\Pages;

use App\Filament\Negocio\Resources\Trabajadors\TrabajadorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTrabajadors extends ManageRecords
{
    protected static string $resource = TrabajadorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
