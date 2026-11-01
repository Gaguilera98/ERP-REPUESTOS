<?php

namespace App\Filament\Negocio\Resources\Trabajadors\Schemas;

use App\Models\TenantUser;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class TrabajadorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                TextInput::make('cedula')
                    ->label('Cédula')
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                TextInput::make('celular')
                    ->label('Celular')
                    ->tel()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                Select::make('user_id')
                    ->label('Usuario del sistema')
                    ->placeholder('No entra al sistema')
                    ->options(fn (): array => TenantUser::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
            ]);
    }
}
