<?php

namespace App\Filament\Negocio\Resources\CategoriaGastos\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoriaGastoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Nombre')->required()->maxLength(255),
            Textarea::make('descripcion')->label('Descripción')->rows(2),
            Toggle::make('is_active')->label('Activa')->default(true),
        ]);
    }
}
