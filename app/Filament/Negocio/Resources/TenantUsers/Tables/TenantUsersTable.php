<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Tables;

use App\Filament\Negocio\Resources\TenantUsers\PermisosExtras;
use App\Filament\Tables\OrdenRegistro;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TenantUsersTable
{
    public static function configure(Table $table): Table
    {
        return OrdenRegistro::aplicar($table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Rol')
                    ->formatStateUsing(fn (string $state): string => AccesoSucursales::etiquetaRol($state))
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->recordActions([
                PermisosExtras::accion(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]));
    }
}
