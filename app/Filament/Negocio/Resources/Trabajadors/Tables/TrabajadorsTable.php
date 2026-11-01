<?php

namespace App\Filament\Negocio\Resources\Trabajadors\Tables;

use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TrabajadorsTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('nombres')
                    ->label('Nombres')
                    ->searchable(),
                TextColumn::make('apellidos')
                    ->label('Apellidos')
                    ->searchable(),
                TextColumn::make('cedula')
                    ->label('Cédula')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('celular')
                    ->label('Celular')
                    ->placeholder('—'),
                TextColumn::make('usuario.name')
                    ->label('Usuario')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay trabajadores')
            ->emptyStateDescription('Cargá a quien recibe la mercadería si querés dejarlo en la compra.')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup));
    }
}
