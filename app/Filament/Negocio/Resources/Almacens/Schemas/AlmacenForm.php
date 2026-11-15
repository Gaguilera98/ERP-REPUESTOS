<?php

namespace App\Filament\Negocio\Resources\Almacens\Schemas;

use App\Models\Almacen;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class AlmacenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([self::nombre()]);
    }

    public static function nombre(): TextInput
    {
        return TextInput::make('nombre')
            ->label('Nombre')
            ->required()
            ->maxLength(255)
            ->unique(
                table: Almacen::class,
                ignorable: fn (mixed $record): ?Almacen => $record instanceof Almacen ? $record : null,
                ignoreRecord: false,
                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey()),
            );
    }
}
