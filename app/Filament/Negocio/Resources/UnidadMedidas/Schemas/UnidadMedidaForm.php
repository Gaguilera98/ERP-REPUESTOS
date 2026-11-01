<?php

namespace App\Filament\Negocio\Resources\UnidadMedidas\Schemas;

use App\Enums\MagnitudUnidad;
use App\Enums\UsoUnidad;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class UnidadMedidaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->placeholder('Ej: Milímetro')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                TextInput::make('simbolo')
                    ->label('Símbolo')
                    ->placeholder('Ej: mm')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                Select::make('magnitud')
                    ->label('Magnitud')
                    ->options(collect(MagnitudUnidad::cases())->mapWithKeys(fn (MagnitudUnidad $magnitud): array => [
                        $magnitud->value => $magnitud->etiqueta(),
                    ])->all())
                    ->required()
                    ->native(false),
                Select::make('uso')
                    ->label('Para qué sirve')
                    ->options(collect(UsoUnidad::cases())->mapWithKeys(fn (UsoUnidad $uso): array => [
                        $uso->value => $uso->etiqueta(),
                    ])->all())
                    ->default(UsoUnidad::Atributo->value)
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('factor_a_referencia')
                    ->label('Cuántas de la referencia')
                    ->helperText('El kilo vale 1000 gramos. La pieza vale 1.')
                    ->numeric()
                    ->minValue(0.000001)
                    ->default(1)
                    ->required(fn (Get $get): bool => $get('uso') === UsoUnidad::Cantidad->value)
                    ->visible(fn (Get $get): bool => $get('uso') === UsoUnidad::Cantidad->value),
                TextInput::make('decimales')
                    ->label('Decimales')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(4)
                    ->default(0)
                    ->required(fn (Get $get): bool => $get('uso') === UsoUnidad::Cantidad->value)
                    ->visible(fn (Get $get): bool => $get('uso') === UsoUnidad::Cantidad->value),
                Toggle::make('es_referencia')
                    ->label('Es la referencia de su familia')
                    ->default(false)
                    ->visible(fn (Get $get): bool => $get('uso') === UsoUnidad::Cantidad->value),
            ]);
    }
}
