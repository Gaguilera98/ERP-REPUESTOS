<?php

namespace App\Filament\Negocio\Resources\Sucursals\Schemas;

use App\Models\Almacen;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class SucursalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                Textarea::make('direccion')
                    ->label('Dirección')
                    ->rows(2)
                    ->columnSpanFull(),
                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Activa')
                    ->default(true),
                Repeater::make('asignaciones')
                    ->label('Almacenes')
                    ->relationship()
                    ->schema([
                        Select::make('almacen_id')
                            ->label('Almacén')
                            ->options(fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->required()
                            ->native(false)
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Toggle::make('es_principal')
                            ->label('Principal de esta sucursal')
                            ->default(false),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
