<?php

namespace App\Filament\Negocio\Resources\TenantUsers\Schemas;

use App\CajaChica\Permisos;
use App\Models\NegocioRole;
use App\Models\Sucursal;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
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
                        ->orderBy('name')
                        ->pluck('name')
                        ->mapWithKeys(fn (string $name): array => [$name => Str::headline($name)])
                        ->all())
                    ->required()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
                Select::make('sucursales')
                    ->label('Sucursales de caja chica')
                    ->helperText('Hacen falta para operar un cajón ajeno, reponer entre cajas o revisar otra sucursal.')
                    ->multiple()
                    ->options(fn (): array => Sucursal::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->native(false),
                Select::make('permisos_directos')
                    ->label('Permisos directos de caja chica')
                    ->helperText('Se suman al rol. No reemplazan los permisos del rol.')
                    ->multiple()
                    ->options(Permisos::etiquetas())
                    ->native(false),
            ]);
    }
}
