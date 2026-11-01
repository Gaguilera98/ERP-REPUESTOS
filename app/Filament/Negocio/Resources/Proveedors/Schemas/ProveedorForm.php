<?php

namespace App\Filament\Negocio\Resources\Proveedors\Schemas;

use App\Models\Proveedor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class ProveedorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Proveedor')
                    ->description('El nombre alcanza para darlo de alta. El ocasional del negocio ya viene creado.')
                    ->icon(Heroicon::OutlinedTruck)
                    ->schema([
                        TextInput::make('nombre_empresa')
                            ->label('Empresa')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (?Proveedor $record): bool => (bool) $record?->es_ocasional)
                            ->dehydrated(fn (?Proveedor $record): bool => ! $record?->es_ocasional),
                        TextInput::make('nit')
                            ->label('NIT')
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                        Textarea::make('direccion')
                            ->label('Dirección')
                            ->rows(2),
                    ]),
                Section::make('Contactos')
                    ->description('Quién atiende. Solo uno puede ser el principal.')
                    ->schema([
                        Repeater::make('contactos')
                            ->hiddenLabel()
                            ->relationship()
                            ->defaultItems(0)
                            ->addActionLabel('Agregar contacto')
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('nombres')
                                    ->label('Nombres')
                                    ->required()
                                    ->maxLength(255)
                                    ->dehydrateStateUsing(fn (string $state): string => trim($state)),
                                TextInput::make('apellidos')
                                    ->label('Apellidos')
                                    ->required()
                                    ->maxLength(255)
                                    ->dehydrateStateUsing(fn (string $state): string => trim($state)),
                                TextInput::make('cargo')
                                    ->label('Cargo')
                                    ->maxLength(255),
                                TextInput::make('telefono')
                                    ->label('Teléfono')
                                    ->tel()
                                    ->maxLength(255),
                                TextInput::make('email')
                                    ->label('Correo')
                                    ->email()
                                    ->maxLength(255),
                                Toggle::make('es_principal')
                                    ->label('Principal')
                                    ->default(false),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
