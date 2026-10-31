<?php

namespace App\Filament\Negocio\Resources\UnidadMedidas\Schemas;

use App\MagnitudUnidad;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
            ]);
    }
}
