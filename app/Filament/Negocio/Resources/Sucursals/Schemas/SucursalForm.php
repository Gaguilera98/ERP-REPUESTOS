<?php

namespace App\Filament\Negocio\Resources\Sucursals\Schemas;

use App\Filament\Negocio\Resources\Almacens\Schemas\AlmacenForm;
use App\Models\Almacen;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Unique;

class SucursalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])
                    ->schema([
                        Section::make('Sucursal')
                            ->description('Nombre, contacto y si el local está abierto.')
                            ->icon(Heroicon::OutlinedBuildingStorefront)
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->schema([
                                TextInput::make('nombre')
                                    ->label('Nombre')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                                TextInput::make('telefono')
                                    ->label('Teléfono')
                                    ->tel()
                                    ->maxLength(255),
                                Textarea::make('direccion')
                                    ->label('Dirección')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                Toggle::make('is_active')
                                    ->label('Activa')
                                    ->default(true)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Almacenes')
                            ->description('Depósitos de los que vende. Solo uno puede ser el principal.')
                            ->icon(Heroicon::OutlinedArchiveBox)
                            ->schema([
                                Repeater::make('asignaciones')
                                    ->hiddenLabel()
                                    ->relationship()
                                    ->defaultItems(0)
                                    ->addActionLabel('Agregar almacén')
                                    ->reorderable(false)
                                    ->columns([
                                        'default' => 1,
                                        'sm' => 2,
                                    ])
                                    ->schema([
                                        Select::make('almacen_id')
                                            ->label('Almacén')
                                            ->placeholder('Elegí un almacén')
                                            ->options(fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                                            ->required()
                                            ->searchable()
                                            ->native(false)
                                            ->createOptionForm([AlmacenForm::nombre()])
                                            ->createOptionAction(fn (Action $action): Action => $action
                                                ->authorize(fn (): bool => Filament::auth()->user()?->can('create', Almacen::class) ?? false)
                                                ->modalHeading('Nuevo almacén'))
                                            ->createOptionUsing(function (array $data): int {
                                                Gate::forUser(Filament::auth()->user())->authorize('create', Almacen::class);

                                                return Almacen::query()->create($data)->getKey();
                                            })
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                        Toggle::make('es_principal')
                                            ->label('Principal de esta sucursal')
                                            ->default(false),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
