<?php

namespace App\Filament\Negocio\Resources\TipoDocumentoIdentidads\Schemas;

use App\Enums\TipoAtributoCategoria;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class TipoDocumentoIdentidadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Documento')
                    ->description('Código para facturación · nombre al elegir el documento en el cliente.')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 12,
                        ])->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->placeholder('ci, nit, licencia…')
                                ->required()
                                ->maxLength(50)
                                ->extraInputAttributes(['class' => 'font-mono'])
                                ->dehydrateStateUsing(fn (string $state): string => strtolower(trim($state)))
                                ->unique(
                                    ignoreRecord: true,
                                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey()),
                                )
                                ->columnSpan(['md' => 2]),
                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->placeholder('Cédula de identidad')
                                ->required()
                                ->maxLength(255)
                                ->dehydrateStateUsing(fn (string $state): string => trim($state))
                                ->columnSpan(['md' => 5]),
                            TextInput::make('orden')
                                ->label('Orden')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->required()
                                ->columnSpan(['md' => 2]),
                            Toggle::make('is_active')
                                ->label('Activo')
                                ->default(true)
                                ->inline(false)
                                ->columnSpan(['md' => 3]),
                        ]),
                        Grid::make(6)
                            ->schema([
                                Toggle::make('para_persona_natural')
                                    ->label('Persona natural')
                                    ->default(true)
                                    ->inline(false)
                                    ->columnSpan(1),
                                Toggle::make('para_empresa')
                                    ->label('Empresa')
                                    ->default(false)
                                    ->inline(false)
                                    ->columnSpan(1),
                            ]),
                    ]),
                Section::make('Campos')
                    ->description('Texto o número van solos. Lista suma las opciones abajo.')
                    ->icon(Heroicon::OutlinedQueueList)
                    ->schema([
                        Repeater::make('atributos')
                            ->hiddenLabel()
                            ->relationship()
                            ->orderColumn('orden')
                            ->collapsible()
                            ->cloneable()
                            ->defaultItems(1)
                            ->addActionLabel('Agregar campo')
                            ->addActionAlignment(Alignment::Start)
                            ->itemLabel(function (array $state): string {
                                $etiqueta = filled($state['etiqueta'] ?? null) ? (string) $state['etiqueta'] : 'Campo nuevo';
                                $tipo = TipoAtributoCategoria::tryFrom((string) ($state['tipo'] ?? ''))?->etiqueta();
                                $marcas = collect([
                                    ! empty($state['obligatorio']) ? 'oblig.' : null,
                                    ! empty($state['identifica']) ? 'único' : null,
                                ])->filter()->implode(', ');

                                $extra = collect([$tipo, $marcas])->filter()->implode(' · ');

                                return $extra !== '' ? "{$etiqueta} · {$extra}" : $etiqueta;
                            })
                            ->schema([
                                Grid::make([
                                    'default' => 1,
                                    'md' => 12,
                                ])->schema([
                                    TextInput::make('etiqueta')
                                        ->label('Etiqueta')
                                        ->placeholder('Número, complemento…')
                                        ->required()
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                            if (filled($get('clave')) || blank($state)) {
                                                return;
                                            }

                                            $set('clave', Str::of($state)->ascii()->snake()->toString());
                                        })
                                        ->columnSpan(['md' => 4]),
                                    TextInput::make('clave')
                                        ->label('Clave')
                                        ->placeholder('numero')
                                        ->required()
                                        ->alphaDash()
                                        ->maxLength(255)
                                        ->distinct()
                                        ->extraInputAttributes(['class' => 'font-mono text-sm'])
                                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                                            ? Str::of($state)->ascii()->snake()->toString()
                                            : null)
                                        ->columnSpan(['md' => 3]),
                                    Select::make('tipo')
                                        ->label('Tipo')
                                        ->options(collect(TipoAtributoCategoria::cases())->mapWithKeys(
                                            fn (TipoAtributoCategoria $tipo): array => [$tipo->value => $tipo->etiqueta()],
                                        )->all())
                                        ->default(TipoAtributoCategoria::Texto->value)
                                        ->required()
                                        ->native(false)
                                        ->live()
                                        ->afterStateUpdated(function (?string $state, Set $set): void {
                                            if ($state !== TipoAtributoCategoria::Lista->value) {
                                                $set('opciones', null);
                                            }
                                        })
                                        ->columnSpan(['md' => 3]),
                                    Flex::make([
                                        Toggle::make('obligatorio')
                                            ->label('Obligatorio')
                                            ->default(false)
                                            ->inline(false),
                                        Toggle::make('identifica')
                                            ->label('Único')
                                            ->default(false)
                                            ->inline(false),
                                    ])
                                        ->from('md')
                                        ->verticalAlignment(VerticalAlignment::End)
                                        ->columnSpan(['md' => 2]),
                                    TagsInput::make('opciones')
                                        ->label('Opciones')
                                        ->placeholder('Enter para agregar: SCZ, LP, A…')
                                        ->visible(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                        ->required(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                        ->dehydrated(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                        ->columnSpanFull(),
                                ]),
                            ]),
                    ]),
            ]);
    }
}
