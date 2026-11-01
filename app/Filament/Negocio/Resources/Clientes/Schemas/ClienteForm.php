<?php

namespace App\Filament\Negocio\Resources\Clientes\Schemas;

use App\Enums\TipoAtributoCategoria;
use App\Enums\TipoCliente;
use App\Enums\TipoPrecioCliente;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Cliente')
                    ->description('Elegí si es persona o empresa. Los documentos se arman con los campos configurados en Tipos de documento.')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->columns(2)
                    ->schema([
                        Select::make('tipo_cliente')
                            ->label('Tipo de cliente')
                            ->options(collect(TipoCliente::cases())->mapWithKeys(
                                fn (TipoCliente $tipo): array => [$tipo->value => $tipo->etiqueta()],
                            )->all())
                            ->default(TipoCliente::PersonaNatural->value)
                            ->required()
                            ->live()
                            ->native(false)
                            ->columnSpanFull(),
                        TextInput::make('nombres')
                            ->label('Nombre')
                            ->required(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->dehydrated(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                        TextInput::make('apellidos')
                            ->label('Apellidos')
                            ->required(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->dehydrated(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::PersonaNatural->value)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                        TextInput::make('razon_social')
                            ->label('Razón social')
                            ->required(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::Empresa->value)
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::Empresa->value)
                            ->dehydrated(fn (Get $get): bool => $get('tipo_cliente') === TipoCliente::Empresa->value)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->columnSpanFull(),
                        Select::make('tipo_precio')
                            ->label('Tipo de precio')
                            ->options(collect(TipoPrecioCliente::cases())->mapWithKeys(
                                fn (TipoPrecioCliente $tipo): array => [$tipo->value => $tipo->etiqueta()],
                            )->all())
                            ->default(TipoPrecioCliente::Menor->value)
                            ->required()
                            ->native(false),
                        TextInput::make('telefono')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Correo')
                            ->email()
                            ->maxLength(255),
                        Textarea::make('direccion')
                            ->label('Dirección')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Documentos')
                    ->description('Podés guardar CI y NIT juntos. Cada tipo muestra solo los campos que configuraste.')
                    ->schema([
                        Repeater::make('documentos')
                            ->hiddenLabel()
                            ->relationship()
                            ->defaultItems(0)
                            ->addActionLabel('Agregar documento')
                            ->reorderable(false)
                            ->schema([
                                Select::make('tipo_documento_identidad_id')
                                    ->label('Tipo')
                                    ->options(function (Get $get): array {
                                        $tipoCliente = TipoCliente::tryFrom((string) $get('../../tipo_cliente'));

                                        $query = TipoDocumentoIdentidad::query()
                                            ->where('is_active', true)
                                            ->orderBy('orden')
                                            ->orderBy('nombre');

                                        if ($tipoCliente === TipoCliente::Empresa) {
                                            $query->where('para_empresa', true);
                                        } else {
                                            $query->where('para_persona_natural', true);
                                        }

                                        return $query->pluck('nombre', 'id')->all();
                                    })
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpanFull(),
                                Group::make()
                                    ->columns(2)
                                    ->schema(fn (Get $get): array => self::camposParaTipo($get('tipo_documento_identidad_id')))
                                    ->visible(fn (Get $get): bool => filled($get('tipo_documento_identidad_id')))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    /**
     * @return array<int, TextInput|Select>
     */
    private static function camposParaTipo(mixed $tipoId): array
    {
        if (! filled($tipoId)) {
            return [];
        }

        /** @var Collection<int, TipoDocumentoAtributo> $atributos */
        $atributos = TipoDocumentoIdentidad::query()
            ->with('atributos')
            ->find($tipoId)
            ?->atributos ?? collect();

        return $atributos
            ->map(function (TipoDocumentoAtributo $atributo): TextInput|Select {
                $nombre = 'valores_json.'.$atributo->clave;

                return match ($atributo->tipo) {
                    TipoAtributoCategoria::Lista => Select::make($nombre)
                        ->label($atributo->etiqueta)
                        ->options(collect($atributo->opciones ?? [])
                            ->mapWithKeys(fn (mixed $opcion): array => [(string) $opcion => (string) $opcion])
                            ->all())
                        ->required($atributo->obligatorio)
                        ->native(false),
                    TipoAtributoCategoria::Decimal => TextInput::make($nombre)
                        ->label($atributo->etiqueta)
                        ->numeric()
                        ->required($atributo->obligatorio),
                    TipoAtributoCategoria::Texto => TextInput::make($nombre)
                        ->label($atributo->etiqueta)
                        ->maxLength(255)
                        ->required($atributo->obligatorio)
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null),
                };
            })
            ->values()
            ->all();
    }
}
