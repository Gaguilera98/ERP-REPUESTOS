<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Filament\Tables\OrdenRegistro;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('domains.domain')
                    ->label('Subdominio')
                    ->searchable(),
                TextColumn::make('db_pool')
                    ->label('Base')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]));
    }
}
