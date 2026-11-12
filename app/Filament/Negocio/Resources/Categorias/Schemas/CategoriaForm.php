<?php

namespace App\Filament\Negocio\Resources\Categorias\Schemas;

use App\Enums\TipoAtributoCategoria;
use App\Enums\UsoUnidad;
use App\Models\UnidadMedida;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CategoriaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Rubro y ficha técnica')
                    ->description('Los atributos definidos aquí son los únicos campos que pide cada variante de este rubro.')
                    ->icon(Heroicon::OutlinedTag)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('nombre')
                            ->label('Nombre del rubro / categoría')
                            ->placeholder('Ej: Rodamientos, Filtros de Aceite, Frenos, Suspensión...')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', tenant()->getTenantKey())),
                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true)
                            ->helperText('Apagada no sale al crear un producto. Los que ya la usan la conservan.'),
                        Toggle::make('sin_devolucion_comercial')
                            ->label('No se devuelve ni se cambia')
                            ->default(false)
                            ->helperText('Las piezas de este rubro no entran en una devolución o cambio comercial, salvo que el producto diga lo contrario. No bloquea un reclamo por falla.'),
                        Repeater::make('atributos')
                            ->label('Atributos de la ficha')
                            ->relationship()
                            ->orderColumn('orden')
                            ->collapsible()
                            ->addActionLabel('Agregar atributo')
                            ->itemLabel(fn (array $state): string => filled($state['etiqueta'] ?? null) ? (string) $state['etiqueta'] : 'Atributo')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('etiqueta')
                                            ->label('Etiqueta')
                                            ->placeholder('Ej: Diámetro interno')
                                            ->required()
                                            ->maxLength(255)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                                if (filled($get('clave')) || blank($state)) {
                                                    return;
                                                }

                                                $set('clave', Str::of($state)->ascii()->snake()->toString());
                                            }),
                                        TextInput::make('clave')
                                            ->label('Clave')
                                            ->placeholder('Ej: diametro_interno')
                                            ->helperText('Identidad estable. No incluye la unidad.')
                                            ->required()
                                            ->alphaDash()
                                            ->maxLength(255)
                                            ->distinct()
                                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Str::of($state)->ascii()->snake()->toString() : null),
                                        Select::make('tipo')
                                            ->label('Tipo')
                                            ->options(collect(TipoAtributoCategoria::cases())->mapWithKeys(fn (TipoAtributoCategoria $tipo): array => [
                                                $tipo->value => $tipo->etiqueta(),
                                            ])->all())
                                            ->required()
                                            ->native(false)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                                if ($state !== TipoAtributoCategoria::Decimal->value) {
                                                    $set('unidad_medida_id', null);
                                                }

                                                if ($state !== TipoAtributoCategoria::Lista->value) {
                                                    $set('opciones', null);
                                                }
                                            }),
                                        Select::make('unidad_medida_id')
                                            ->label('Unidad')
                                            ->helperText('Solo si el decimal se mide. Vacío para un número sin escala.')
                                            ->options(fn (): array => self::opcionesDeUnidad())
                                            ->searchable()
                                            ->preload()
                                            ->native(false)
                                            ->visible(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Decimal->value)
                                            ->dehydrated(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Decimal->value),
                                        Toggle::make('obligatorio')
                                            ->label('Obligatorio')
                                            ->default(false)
                                            ->inline(false),
                                        TagsInput::make('opciones')
                                            ->label('Opciones')
                                            ->placeholder('Ej: 2RS')
                                            ->helperText('Cada valor es una opción cerrada.')
                                            ->visible(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                            ->required(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                            ->dehydrated(fn (Get $get): bool => $get('tipo') === TipoAtributoCategoria::Lista->value)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * Cada grupo es un arreglo de textos. Si queda una colección, el select muestra "[object Object]".
     *
     * @return array<string, array<int, string>>
     */
    public static function opcionesDeUnidad(): array
    {
        return UnidadMedida::query()
            ->where('uso', UsoUnidad::Atributo)
            ->orderBy('nombre')
            ->get()
            ->groupBy(fn (UnidadMedida $unidad): string => $unidad->magnitud->etiqueta())
            ->map(fn ($grupo): array => $grupo->mapWithKeys(fn (UnidadMedida $unidad): array => [
                $unidad->id => $unidad->nombre.' ('.$unidad->simbolo.')',
            ])->all())
            ->all();
    }
}
