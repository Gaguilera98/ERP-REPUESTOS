<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Schemas;

use App\Catalogo\VariantesProducto;
use App\Enums\TipoAtributoCategoria;
use App\Filament\Negocio\Resources\Marcas\Schemas\MarcaForm;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\Marca;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ProductoMaestroForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 10])
                    ->schema([
                        Section::make('Datos del producto')
                            ->description('Nombre, rubro y marca. El código y el precio van en este mismo producto.')
                            ->columnSpan(['default' => 1, 'lg' => 6])
                            ->schema([
                                TextInput::make('nombre')
                                    ->label('Nombre del producto')
                                    ->placeholder('Ej: Rodamiento rígido de bolas, Polera de hombre...')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('categoria_id')
                                    ->label('Categoría / Rubro')
                                    ->options(fn (Get $get): array => self::catalogoActivo(Categoria::class, $get, 'categoria_id'))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('con_variantes', false);
                                        $set('opciones', []);
                                        $set('variantes', []);
                                        $set('ficha', []);
                                    }),
                                Select::make('marca_id')
                                    ->label('Marca')
                                    ->options(fn (Get $get): array => self::catalogoActivo(Marca::class, $get, 'marca_id'))
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->placeholder('Sin marca')
                                    ->createOptionForm([
                                        MarcaForm::nombre(),
                                    ])
                                    ->createOptionUsing(fn (array $data): int => Marca::query()->create($data)->getKey())
                                    ->createOptionAction(fn (Action $action): Action => $action
                                        ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Marca::class))
                                        ->modalHeading('Nueva marca')),
                                Toggle::make('is_active')
                                    ->label('Activo')
                                    ->default(true)
                                    ->helperText('Apagado no se ofrece. Cada variante se apaga sola en su fila.'),
                            ]),
                        Section::make('Galería')
                            ->description('Fotos del producto. La foto de una variante también entra acá.')
                            ->columnSpan(['default' => 1, 'lg' => 4])
                            ->schema([
                                FileUpload::make('imagenes')
                                    ->hiddenLabel()
                                    ->multiple()
                                    ->panelLayout('grid')
                                    ->reorderable()
                                    ->appendFiles()
                                    ->image()
                                    ->maxFiles(5)
                                    ->directory(fn (): string => 'tenants/'.tenant()->getTenantKey().'/productos/galeria')
                                    ->disk(fn (): string => config('filesystems.default') === 's3' ? 's3' : 'public')
                                    ->visibility('public')
                                    ->helperText('Hasta 5 imágenes. Arrastrá para ordenar.'),
                            ]),
                    ]),

                Section::make('Precio')
                    ->description('Un solo código. Si más adelante tiene color, talla u otra lista, agregá variantes.')
                    ->visible(fn (Get $get): bool => ! self::conVariantes($get))
                    ->schema([
                        Hidden::make('variante_id'),
                        Grid::make(2)
                            ->schema([
                                self::campoSku('sku', 'variante_id'),
                                self::campoCodigoBarras('codigo_barras', 'variante_id'),
                                self::campoPrecio('precio_venta', 'Precio al por menor', true),
                                self::campoPrecio('precio_mayor', 'Precio al por mayor', false),
                            ]),
                        self::ubicaciones(),
                    ]),

                Section::make('Variantes')
                    ->description('Si tiene color, talla o sello, activalo. Cada combinación lleva su código y su precio.')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->visible(fn (Get $get): bool => self::listas($get)->isNotEmpty())
                    ->schema([
                        Toggle::make('con_variantes')
                            ->label('Agregar variantes')
                            ->onColor('warning')
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(function (bool $state, Set $set, Get $get): void {
                                if ($state) {
                                    if (blank($get('opciones'))) {
                                        $set('opciones', [[
                                            'clave' => null,
                                            'valores' => [],
                                        ]]);
                                    }

                                    return;
                                }

                                $primera = collect($get('variantes'))->first();
                                $primera = is_array($primera) ? $primera : [];
                                $variantes = app(VariantesProducto::class);

                                $set('variante_id', $primera['id'] ?? $get('variante_id'));
                                $set('sku', $primera['sku'] ?? $get('sku'));
                                $set('codigo_barras', $primera['codigo_barras'] ?? $get('codigo_barras'));
                                $set('precio_venta', $primera['precio_venta'] ?? $get('precio_venta'));
                                $set('precio_mayor', $primera['precio_mayor'] ?? $get('precio_mayor'));

                                $ficha = is_array($get('ficha')) ? $get('ficha') : [];
                                $ejes = $variantes->ejes($primera);

                                foreach (array_keys($ejes) as $clave) {
                                    if (self::listas($get)->has($clave)) {
                                        $ejes[$clave] = [$ejes[$clave]];
                                    }
                                }

                                $set('ficha', array_merge($ficha, $ejes));
                                $set('opciones', []);
                                $set('variantes', []);
                            }),
                        Placeholder::make('opciones_ayuda')
                            ->hiddenLabel()
                            ->visible(fn (Get $get): bool => self::conVariantes($get))
                            ->content('Cada opción es un campo de la categoría. Los valores son los que este producto realmente tiene.'),
                        Repeater::make('opciones')
                            ->visible(fn (Get $get): bool => self::conVariantes($get))
                            ->hiddenLabel()
                            ->minItems(1)
                            ->addActionLabel('Agregar otra opción')
                            ->itemLabel(function (array $state): string {
                                $clave = $state['clave'] ?? null;

                                if (! filled($clave)) {
                                    return 'Opción';
                                }

                                return CategoriaAtributo::query()->where('clave', $clave)->value('etiqueta')
                                    ?: (string) $clave;
                            })
                            ->live()
                            ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                                $set('variantes', app(VariantesProducto::class)->combinar(
                                    $state ?? [],
                                    is_array($get('variantes')) ? $get('variantes') : [],
                                    $get('sku'),
                                    $get('precio_venta'),
                                    $get('precio_mayor'),
                                    $get('codigo_barras'),
                                ));
                            })
                            ->schema([
                                Select::make('clave')
                                    ->label('Qué las distingue')
                                    ->options(fn (Get $get): array => self::listas($get)
                                        ->mapWithKeys(fn (CategoriaAtributo $atributo): array => [$atributo->clave => $atributo->etiqueta])
                                        ->all())
                                    ->required()
                                    ->native(false)
                                    ->searchable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('valores', [])),
                                CheckboxList::make('valores')
                                    ->label('Valores de este producto')
                                    ->options(fn (Get $get): array => self::opcionesDeLista($get))
                                    ->visible(fn (Get $get): bool => filled($get('clave')))
                                    ->required()
                                    ->columns(2)
                                    ->live()
                                    ->hintAction(self::accionAgregarValor()),
                            ]),
                    ]),

                Section::make('Combinaciones')
                    ->description('Cada fila es una combinación que se vende. Borrá las que no tenés.')
                    ->visible(function (Get $get): bool {
                        if (! self::conVariantes($get)) {
                            return false;
                        }

                        return collect($get('variantes'))->contains(
                            fn (mixed $fila): bool => is_array($fila) && filled($fila['ejes_json'] ?? null),
                        );
                    })
                    ->schema([
                        Repeater::make('variantes')
                            ->hiddenLabel()
                            ->addable(false)
                            ->reorderable(false)
                            ->cloneable(false)
                            ->minItems(2)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => app(VariantesProducto::class)->etiqueta($state))
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('ejes_json'),
                                Grid::make(12)
                                    ->schema([
                                        FileUpload::make('imagen')
                                            ->label('Foto')
                                            ->image()
                                            ->avatar()
                                            ->helperText('También queda en la galería.')
                                            ->directory(fn (): string => 'tenants/'.tenant()->getTenantKey().'/productos/variantes')
                                            ->disk(fn (): string => config('filesystems.default') === 's3' ? 's3' : 'public')
                                            ->visibility('public')
                                            ->columnSpan([
                                                'default' => 12,
                                                'sm' => 3,
                                            ]),
                                        Grid::make(2)
                                            ->columnSpan([
                                                'default' => 12,
                                                'sm' => 9,
                                            ])
                                            ->schema([
                                                self::campoSku('sku', 'id'),
                                                self::campoCodigoBarras('codigo_barras', 'id'),
                                                self::campoPrecio('precio_venta', 'Precio al por menor', true),
                                                self::campoPrecio('precio_mayor', 'Precio al por mayor', false),
                                                Toggle::make('is_active')
                                                    ->label('Se vende')
                                                    ->default(true)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                                self::ubicaciones(),
                            ]),
                    ]),

                Section::make('Ficha')
                    ->description('Datos del rubro. En una lista podés marcar uno o varios. Si hay variantes, estos valen para todas.')
                    ->schema(fn (Get $get): array => self::camposFicha($get)),
            ]);
    }

    /**
     * @param  class-string<Categoria|Marca>  $modelo
     * @return array<int|string, string>
     */
    private static function catalogoActivo(string $modelo, Get $get, string $campo): array
    {
        return $modelo::query()
            ->where(function ($query) use ($get, $campo): void {
                $query->where('is_active', true);

                if (filled($get($campo))) {
                    $query->orWhere('id', $get($campo));
                }
            })
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();
    }

    private static function campoSku(string $nombre, string $id): TextInput
    {
        return TextInput::make($nombre)
            ->label('SKU / Código')
            ->required()
            ->maxLength(255)
            ->distinct()
            ->scopedUnique(
                model: ProductoVariante::class,
                ignorable: fn (Get $get) => $get($id),
                ignoreRecord: false,
            );
    }

    private static function campoCodigoBarras(string $nombre, string $id): TextInput
    {
        return TextInput::make($nombre)
            ->label('Código de barras')
            ->maxLength(255)
            ->nullable()
            ->distinct()
            ->scopedUnique(
                model: ProductoVariante::class,
                ignorable: fn (Get $get) => $get($id),
                ignoreRecord: false,
            )
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null);
    }

    private static function campoPrecio(string $nombre, string $etiqueta, bool $obligatorio): TextInput
    {
        $campo = TextInput::make($nombre)
            ->label($etiqueta)
            ->numeric()
            ->minValue(0)
            ->prefix(self::moneda());

        if ($obligatorio) {
            $campo->required();
        } else {
            $campo->nullable();
        }

        return $campo;
    }

    private static function ubicaciones(): Repeater
    {
        return Repeater::make('ubicaciones')
            ->label('Ubicación en almacenes')
            ->hiddenOn('create')
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->collapsed()
            ->schema([
                Hidden::make('id'),
                Select::make('almacen_id')
                    ->label('Almacén')
                    ->options(fn (): array => Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('ubicacion')
                    ->label('Ubicación / Estante')
                    ->maxLength(255)
                    ->placeholder('Ej: Pasillo 2 - Estante B'),
            ]);
    }

    /**
     * @return list<Placeholder|Select|TextInput>
     */
    private static function camposFicha(Get $get): array
    {
        $atributos = self::atributos($get);

        if ($atributos->isEmpty()) {
            return [
                Placeholder::make('ficha_vacia')
                    ->hiddenLabel()
                    ->content($get('data.categoria_id', isAbsolute: true)
                        ? 'Esta categoría no tiene atributos.'
                        : 'Elegí una categoría para cargar la ficha.'),
            ];
        }

        return $atributos->map(function (CategoriaAtributo $atributo) use ($get): Select|TextInput {
            $nombre = 'ficha.'.$atributo->clave;
            $esEje = self::clavesEje($get)->contains($atributo->clave);

            $campo = match ($atributo->tipo) {
                TipoAtributoCategoria::Lista => Select::make($nombre)
                    ->multiple()
                    ->options(collect($atributo->opciones ?? [])->mapWithKeys(fn (mixed $opcion): array => [(string) $opcion => (string) $opcion])->all())
                    ->native(false)
                    ->live()
                    ->placeholder('Elegí uno o varios')
                    ->createOptionForm([
                        TextInput::make('valor')
                            ->label('Valor')
                            ->required()
                            ->maxLength(255)
                            ->rule(fn (): Closure => self::reglaValorNuevo($atributo)),
                    ])
                    ->createOptionUsing(fn (array $data): string => $atributo->agregarOpcion((string) ($data['valor'] ?? '')))
                    ->createOptionAction(fn (Action $action): Action => $action
                        ->visible(fn (): bool => self::puedeEditarCategorias())
                        ->modalHeading('Nuevo valor de '.$atributo->etiqueta)),
                TipoAtributoCategoria::Decimal => TextInput::make($nombre)
                    ->numeric()
                    ->minValue(0)
                    ->validationMessages([
                        'min' => $atributo->etiqueta.' no puede ser negativo.',
                    ]),
                TipoAtributoCategoria::Texto => TextInput::make($nombre),
            };

            $campo
                ->label($atributo->etiqueta)
                ->visible(! $esEje);

            if ($atributo->obligatorio && ! $esEje) {
                $campo->required();
            }

            if ($atributo->unidadMedida) {
                $campo->suffix($atributo->unidadMedida->simbolo);
            }

            return $campo;
        })->all();
    }

    private static function accionAgregarValor(): Action
    {
        return Action::make('agregarValor')
            ->label('Agregar valor')
            ->visible(fn (Get $get): bool => filled($get('clave')) && self::puedeEditarCategorias())
            ->modalHeading(function (Get $get): string {
                $atributo = self::listas($get)->get((string) $get('clave'));

                return $atributo ? 'Nuevo valor de '.$atributo->etiqueta : 'Nuevo valor';
            })
            ->modalSubmitActionLabel('Agregar')
            ->fillForm(fn (Get $get): array => [
                'categoria_id' => $get('data.categoria_id', isAbsolute: true),
                'clave' => $get('clave'),
            ])
            ->schema([
                Hidden::make('categoria_id'),
                Hidden::make('clave'),
                TextInput::make('valor')
                    ->label('Valor')
                    ->placeholder('Ej: RS')
                    ->required()
                    ->maxLength(255)
                    ->rule(function (Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $atributo = CategoriaAtributo::query()
                                ->where('categoria_id', $get('categoria_id'))
                                ->where('clave', $get('clave'))
                                ->first();

                            if (! $atributo) {
                                return;
                            }

                            self::rechazarValorRepetido($atributo, $value, $fail);
                        };
                    }),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                if (! self::puedeEditarCategorias()) {
                    return;
                }

                $atributo = self::listas($get)->get((string) $get('clave'));

                if (! $atributo) {
                    return;
                }

                $valor = $atributo->agregarOpcion((string) ($data['valor'] ?? ''));
                $elegidos = collect(is_array($get('valores')) ? $get('valores') : [])
                    ->map(fn (mixed $item): string => (string) $item)
                    ->push($valor)
                    ->unique()
                    ->values()
                    ->all();

                $set('valores', $elegidos);
            });
    }

    /**
     * @return array<string, string>
     */
    private static function opcionesDeLista(Get $get): array
    {
        $atributo = self::listas($get)->get((string) $get('clave'));
        $opciones = is_array($atributo?->opciones) ? $atributo->opciones : [];

        return collect($opciones)
            ->mapWithKeys(fn (mixed $opcion): array => [(string) $opcion => (string) $opcion])
            ->all();
    }

    private static function reglaValorNuevo(CategoriaAtributo $atributo): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($atributo): void {
            $atributo->refresh();
            self::rechazarValorRepetido($atributo, $value, $fail);
        };
    }

    private static function rechazarValorRepetido(CategoriaAtributo $atributo, mixed $valor, Closure $fail): void
    {
        $texto = trim((string) $valor);

        foreach ($atributo->opciones ?? [] as $opcion) {
            if (mb_strtolower((string) $opcion) === mb_strtolower($texto)) {
                $fail('Ya existe un valor con ese nombre.');
            }
        }
    }

    private static function puedeEditarCategorias(): bool
    {
        return (bool) auth('tenant')->user()?->can('Update:Categoria');
    }

    /**
     * @return Collection<string, CategoriaAtributo>
     */
    private static function listas(Get $get): Collection
    {
        return self::atributos($get)
            ->where('tipo', TipoAtributoCategoria::Lista)
            ->keyBy('clave');
    }

    /**
     * @return Collection<int, CategoriaAtributo>
     */
    private static function atributos(Get $get): Collection
    {
        $categoriaId = $get('data.categoria_id', isAbsolute: true);

        if (! $categoriaId) {
            return collect();
        }

        return CategoriaAtributo::query()
            ->with('unidadMedida')
            ->where('categoria_id', $categoriaId)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, string>
     */
    private static function clavesEje(Get $get): Collection
    {
        return collect($get('opciones'))
            ->filter(fn (mixed $opcion): bool => is_array($opcion) && filled($opcion['clave'] ?? null))
            ->pluck('clave')
            ->map(fn (mixed $clave): string => (string) $clave);
    }

    private static function conVariantes(Get $get): bool
    {
        return filter_var($get('con_variantes'), FILTER_VALIDATE_BOOLEAN);
    }

    private static function moneda(): string
    {
        return NegocioConfiguracion::query()->first()?->moneda->value ?? 'BOB';
    }
}
