<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Schemas;

use App\Models\NegocioRole;
use App\Models\Sucursal;
use App\Tenancy\AccesoSucursales;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class TenantUserForm
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
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                Select::make('role')
                    ->label('Rol')
                    ->options(fn (): array => NegocioRole::query()
                        ->where('guard_name', 'tenant')
                        ->when(! auth('tenant')->user()?->hasRole(AccesoSucursales::ADMINISTRADOR), fn ($query) => $query
                            ->where('name', '!=', AccesoSucursales::ADMINISTRADOR))
                        ->orderBy('name')
                        ->pluck('name')
                        ->mapWithKeys(fn (string $name): array => [$name => AccesoSucursales::etiquetaRol($name)])
                        ->all())
                    ->required()
                    ->live()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
                Select::make('sucursales')
                    ->label('Sucursales asignadas')
                    ->helperText(fn (Get $get): string => $get('role') === AccesoSucursales::ADMINISTRADOR
                        ? 'El administrador tiene acceso a todas las sucursales del negocio.'
                        : 'Locales donde puede operar, según los permisos que tenga.')
                    ->multiple()
                    ->disabled(fn (Get $get): bool => $get('role') === AccesoSucursales::ADMINISTRADOR)
                    ->options(fn (): array => Sucursal::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->native(false),
            ]);
    }
}
