<?php

namespace App\Filament\Negocio\Resources\Categorias\Pages;

use App\Filament\Negocio\Resources\Categorias\CategoriaResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCategorias extends ManageRecords
{
    protected static string $resource = CategoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importar')->label('Importar configuración')->color('gray')->icon('heroicon-o-arrow-up-tray')
                ->url(CategoriaResource::getUrl('importar'))->visible(fn (): bool => ImportarConfiguracion::canAccess()),
            CreateAction::make()
                ->stickyModalHeader()
                ->stickyModalFooter(),
        ];
    }
}
