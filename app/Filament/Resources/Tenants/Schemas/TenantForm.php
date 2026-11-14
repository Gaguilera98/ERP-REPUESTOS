<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Models\Tenant;
use App\Rules\NegocioDomainRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                TextInput::make('domain')
                    ->label('Subdominio')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->suffix(fn (): string => '.'.NegocioDomainRule::centralHost())
                    ->helperText(function (Get $get): string {
                        $host = NegocioDomainRule::hostCompleto(
                            is_string($get('domain') ?? null) ? $get('domain') : null,
                        );

                        return $host !== null
                            ? 'Dirección del negocio: https://'.$host.'/negocio'
                            : 'Solo el nombre. Queda nombre.'.NegocioDomainRule::centralHost().'/negocio';
                    })
                    ->rules(fn (?Tenant $record): array => [
                        new NegocioDomainRule($record?->domains()->value('id')),
                    ]),
                Select::make('db_pool')
                    ->label('Base')
                    ->options(self::pools())
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->disabledOn('edit')
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
                Section::make('Usuario inicial')
                    ->description('Entra al negocio con este usuario. Nace como supervisor.')
                    ->visibleOn('create')
                    ->components([
                        TextInput::make('supervisor_name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('supervisor_email')
                            ->label('Correo')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('supervisor_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(255)
                            ->validationMessages([
                                'min' => 'La contraseña del supervisor debe tener al menos 8 caracteres.',
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function pools(): array
    {
        $pools = config('tenant-pools.pools');

        if (! is_array($pools)) {
            return [];
        }

        $options = [];

        foreach ($pools as $key => $pool) {
            $label = is_array($pool) ? ($pool['label'] ?? $key) : $key;
            $options[(string) $key] = (string) $label;
        }

        return $options;
    }
}
