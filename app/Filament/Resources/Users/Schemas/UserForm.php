<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\NegocioRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Correo')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->maxLength(255),
                Select::make('role')
                    ->label('Rol')
                    ->options(fn (): array => NegocioRole::query()
                        ->where('guard_name', 'web')
                        ->orderBy('name')
                        ->pluck('name')
                        ->mapWithKeys(fn (string $name): array => [$name => Str::headline($name)])
                        ->all())
                    ->required()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
            ]);
    }
}
