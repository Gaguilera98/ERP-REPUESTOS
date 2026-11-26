<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Schemas;

use App\Archivos\ArchivosNegocio;
use App\Catalogo\PreciosDeReferencia;
use App\Catalogo\VariantesProducto;
use App\Compras\Montos;
use App\Enums\DevolucionComercialProducto;
use App\Enums\TipoArchivoNegocio;
use App\Enums\TipoAtributoCategoria;
use App\Enums\UsoUnidad;
use App\Filament\Negocio\Resources\Categorias\Schemas\CategoriaForm;
use App\Filament\Negocio\Resources\Marcas\Schemas\MarcaForm;
use App\Models\Categoria;
use App\Models\CategoriaAtributo;
use App\Models\CondicionProducto;
use App\Models\InventarioStock;
use App\Models\Marca;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\UnidadMedida;
use App\Tenancy\AccesoSucursales;
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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ProductoMaestroForm
{
    public static function configure(Schema $schema): Schema
    {
        $conDolar = app(PreciosDeReferencia::class)->habilitado();
        $moneda = NegocioConfiguracion::codigoMoneda();
        $cotizacion = $conDolar ? app(PreciosDeReferencia::class)->vigente($moneda)?->valor : null;

        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'xl' => 12])
                    ->schema([
                        Group::make([
                            Section::make('Datos del producto')
                                ->columns(['default' => 1, 'md' => 2])
                                ->schema([
                                    TextInput::make('nombre')
                                        ->label('Nombre del producto')
                                        ->placeholder('Ej. Rodamiento rígido de bolas')
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                    TextInput::make('codigo_repuesto')
                                        ->label('Código del producto')
                                        ->placeholder('Ej. 6206')
                                        ->maxLength(255)
                                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: 'Código que usa el cliente para pedir el producto. Es compartido por Nuevo, Usado y las demás variantes. Cada variante tiene además su SKU interno único.')
                                        ->columnSpanFull(),
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
                                        })
                                        ->createOptionForm(fn (Schema $schema): Schema => CategoriaForm::configure(
                                            $schema->model(Categoria::class),
                                        ))
                                        ->createOptionUsing(function (array $data, Schema $schema): int {
                                            $categoria = Categoria::query()->create(Arr::only($data, [
                                                'nombre',
                                                'is_active',
                                                'sin_devolucion_comercial',
                                            ]));

                                            $schema->model($categoria)->saveRelationships();

                                            return $categoria->getKey();
                                        })
                                        ->createOptionAction(fn (Action $action): Action => $action
                                            ->visible(fn (): bool => (bool) auth('tenant')->user()?->can('create', Categoria::class))
                                            ->modalHeading('Nueva categoría')
                                            ->stickyModalHeader()
                                            ->stickyModalFooter()),
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
                                    Select::make('devolucion_comercial')
                                        ->label('Devolución comercial')
                                        ->options(collect(DevolucionComercialProducto::cases())->mapWithKeys(
                                            fn (DevolucionComercialProducto $marca): array => [$marca->value => $marca->etiqueta()],
                                        )->all())
                                        ->default(DevolucionComercialProducto::Hereda->value)
                                        ->required()
                                        ->native(false)
                                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: 'La opción «Hereda la categoría» usa la política del rubro. Esta política es para devoluciones comerciales.'),
                                    Select::make('unidad_base_id')
                                        ->label('Unidad de stock')
                                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: 'Unidad utilizada para existencias, costos y precios.')
                                        ->options(fn (): array => self::unidadesDeCantidad())
                                        ->default(fn (): mixed => UnidadMedida::query()->where('simbolo', 'pza')->value('id'))
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        ->native(false),
                                    Toggle::make('is_active')
                                        ->label('Disponible para la venta')
                                        ->default(true)
                                        ->columnSpanFull(),
                                ]),
                            Section::make('Códigos y precios')
                                ->visible(fn (Get $get): bool => ! self::conVariantes($get))
                                ->columns(['default' => 1, 'md' => 2])
                                ->schema([
                                    Hidden::make('variante_id'),
                                    self::campoSku('sku', 'variante_id'),
                                    self::campoCodigoBarras('codigo_barras', 'variante_id'),
                                    self::campoCondicion('variante_id')->columnSpanFull(),
                                    self::campoPrecio('precio_venta', 'Precio al por menor', true, $moneda, $conDolar),
                                    self::campoPrecio('precio_mayor', 'Precio al por mayor', false, $moneda, $conDolar),
                                    self::referencia('precio_venta_dolar', 'Referencia Menor en USD', $conDolar, $moneda, $cotizacion),
                                    self::referencia('precio_mayor_dolar', 'Referencia Mayor en USD', $conDolar, $moneda, $cotizacion),
                                ]),
                            Section::make('Ubicación en almacenes')
                                ->hiddenOn('create')
                                ->visible(fn (Get $get): bool => ! self::conVariantes($get))
                                ->collapsed()
                                ->schema([self::ubicaciones()->hiddenLabel()]),
                        ])->columnSpan(['default' => 1, 'xl' => 7]),
                        Group::make([
                            self::stockPorAlmacen(),
                            Section::make('Galería')
                                ->schema([
                                    View::make('filament.negocio.resources.producto-maestros.visor-fotos')
                                        ->visible(fn (?ProductoMaestro $record): bool => filled($record?->imagenes))
                                        ->viewData(fn (?ProductoMaestro $record): array => [
                                            'fotos' => array_map(Storage::disk('public')->url(...), array_values($record?->imagenes ?? [])),
                                        ]),
                                    FileUpload::make('imagenes')
                                        ->hiddenLabel()
                                        ->multiple()
                                        ->panelLayout('grid')
                                        ->reorderable()
                                        ->appendFiles()
                                        ->image()
                                        ->maxFiles(5)
                                        ->directory(fn (): string => app(ArchivosNegocio::class)->directorio(TipoArchivoNegocio::GaleriaProducto))
                                        ->disk('public')
                                        ->helperText('Hasta 5 fotos. Arrastrá para ordenar. Guardá los cambios para actualizar el visor.'),
                                ]),
                            Section::make('Ficha técnica')
                                ->schema(fn (Get $get): array => self::camposFicha($get)),
                            Section::make('Empaques')
                                ->collapsed()
                                ->schema([self::empaques()]),
                        ])->columnSpan(['default' => 1, 'xl' => 5]),
                    ]),

                Section::make('Variantes')
                    ->description('Usá variantes si ofrecés este producto en varias condiciones, colores, tallas o sellos. Cada variante tiene su SKU, precio y stock.')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->schema([
                        Toggle::make('con_variantes')
                            ->label('Agregar variantes')
                            ->onColor('warning')
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(function (bool $state, Set $set, Get $get): void {
                                if ($state) {
                                    if (blank($get('opciones')) && self::listas($get)->isNotEmpty()) {
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
                                $set('condicion_producto_id', $primera['condicion_producto_id'] ?? $get('condicion_producto_id'));
                                $set('precio_venta', $primera['precio_venta'] ?? $get('precio_venta'));
                                $set('precio_mayor', $primera['precio_mayor'] ?? $get('precio_mayor'));
                                if ($primera !== []) {
                                    $set('precio_venta_dolar', $primera['precio_venta_dolar'] ?? null);
                                    $set('precio_mayor_dolar', $primera['precio_mayor_dolar'] ?? null);
                                }

                                $ficha = is_array($get('ficha')) ? $get('ficha') : [];
                                $ejes = $variantes->ejes($primera);

                                foreach (array_keys($ejes) as $clave) {
                                    if (self::listas($get)->has($clave)) {
                                        $ejes[$clave] = [$ejes[$clave]];
                                    }
                                }

                                $set('ficha', array_merge($ficha, $ejes));
                                $set('opciones', []);
                                $set('condiciones_variantes', []);
                                $set('variantes', []);
                            }),
                        CheckboxList::make('condiciones_variantes')
                            ->label('Condiciones que ofrecés')
                            ->helperText('Por ejemplo, Nuevo y Usado. Se crea una fila por condición; podés combinarla con las opciones de la categoría.')
                            ->options(fn (?ProductoMaestro $record): array => CondicionProducto::query()
                                ->where(fn ($query) => $query->where('is_active', true)
                                    ->when($record !== null, fn ($query) => $query->orWhereIn('id', $record->variantes()->select('condicion_producto_id'))))
                                ->orderBy('orden')->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->default([])
                            ->columns(2)
                            ->visible(fn (Get $get): bool => self::conVariantes($get))
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => self::regenerarVariantes($set, $get)),
                        Placeholder::make('opciones_ayuda')
                            ->hiddenLabel()
                            ->content('También podés distinguir variantes por atributos de la categoría. Si solo cambia la condición, no hace falta agregar otras opciones.')
                            ->visible(fn (Get $get): bool => self::conVariantes($get) && self::listas($get)->isNotEmpty()),
                        Repeater::make('opciones')
                            ->visible(fn (Get $get): bool => self::conVariantes($get) && self::listas($get)->isNotEmpty())
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->minItems(fn (Get $get): int => filled($get('condiciones_variantes')) ? 0 : 1)
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
                            ->afterStateUpdated(fn (Set $set, Get $get) => self::regenerarVariantes($set, $get))
                            ->schema([
                                Select::make('clave')
                                    ->label('Qué las distingue')
                                    ->options(fn (Get $get): array => self::listas($get)
                                        ->mapWithKeys(fn (CategoriaAtributo $atributo): array => [$atributo->clave => $atributo->etiqueta])
                                        ->all())
                                    ->required(fn (Get $get): bool => blank($get('data.condiciones_variantes', isAbsolute: true)))
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
                    ->description('Revisá la condición, el SKU único y el precio de cada variante. Cada una controla su propio stock.')
                    ->visible(fn (Get $get): bool => self::conVariantes($get))
                    ->schema([
                        Repeater::make('variantes')
                            ->hiddenLabel()
                            ->addable(false)
                            ->reorderable(false)
                            ->cloneable(false)
                            ->minItems(2)
                            ->validationMessages(['min' => 'Elegí al menos dos condiciones o combinaciones. Para una sola, desactivá «Agregar variantes».'])
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
                                            ->directory(fn (): string => app(ArchivosNegocio::class)->directorio(TipoArchivoNegocio::FotoVariante))
                                            ->disk('public')
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
                                                self::campoCondicion('id')->columnSpanFull(),
                                                self::campoPrecio('precio_venta', 'Precio al por menor', true, $moneda, $conDolar),
                                                self::campoPrecio('precio_mayor', 'Precio al por mayor', false, $moneda, $conDolar),
                                                self::referencia('precio_venta_dolar', 'Referencia Menor en USD', $conDolar, $moneda, $cotizacion),
                                                self::referencia('precio_mayor_dolar', 'Referencia Mayor en USD', $conDolar, $moneda, $cotizacion),
                                                Toggle::make('is_active')
                                                    ->label('Se vende')
                                                    ->default(true)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                                self::ubicaciones(),
                            ]),
                    ]),

            ]);
    }

    private static function stockPorAlmacen(): Section
    {
        $cantidad = fn (string $state, InventarioStock $record): string => trim(
            Montos::sinCeros($state).' '.$record->variante?->productoMaestro?->unidadBase?->simbolo,
        );

        return Section::make('Stock por almacén')
            ->visible(fn (?ProductoMaestro $record): bool => $record !== null && app(AccesoSucursales::class)->puedeVerStock(auth('tenant')->user()))
            ->schema([
                RepeatableEntry::make('stock_por_almacen')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'producto-stock'])
                    ->placeholder('No hay existencias registradas.')
                    ->state(fn (?ProductoMaestro $record): ?ModelCollection => $record === null ? null
                        : app(AccesoSucursales::class)->filtrarAlmacen($record->stocks()->getQuery(), auth('tenant')->user())
                            ->with('almacen', 'variante.productoMaestro.unidadBase', 'variante.condicion')->get()->sortBy(['almacen.nombre', 'variante.sku']))
                    ->table([
                        TableColumn::make('Almacén'),
                        TableColumn::make('Variante / SKU interno'),
                        TableColumn::make('Físico'),
                        TableColumn::make('Reservado'),
                        TableColumn::make('Disponible'),
                    ])
                    ->schema([
                        TextEntry::make('almacen.nombre')->label('Almacén'),
                        TextEntry::make('variante.sku')->label('Variante / SKU interno')
                            ->formatStateUsing(fn (string $state, InventarioStock $record): string => ($record->variante?->condicion?->nombre ?? 'Sin condición asignada').' · SKU interno: '.$state),
                        TextEntry::make('cantidad_actual')->label('Físico')->formatStateUsing($cantidad),
                        TextEntry::make('cantidad_reservada')->label('Reservado')->formatStateUsing($cantidad),
                        TextEntry::make('cantidad_disponible')
                            ->label('Disponible')
                            ->state(fn (InventarioStock $record): string => bcsub($record->cantidad_actual, $record->cantidad_reservada, 4))
                            ->formatStateUsing($cantidad),
                    ]),
            ]);
    }

    private static function empaques(): Repeater
    {
        return Repeater::make('empaques')
            ->hiddenLabel()
            ->helperText('Indicá cuántas unidades de stock contiene cada empaque.')
            ->relationship()
            ->defaultItems(0)
            ->addActionLabel('Agregar empaque')
            ->schema([
                Select::make('unidad_medida_id')
                    ->label('Unidad de empaque')
                    ->options(fn (): array => self::unidadesDeCantidad())
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),
                TextInput::make('factor_a_base')
                    ->label('Unidades de stock por empaque')
                    ->numeric()
                    ->minValue(0.000001)
                    ->required(),
            ])
            ->columns(2);
    }

    /**
     * @return array<int, string>
     */
    private static function unidadesDeCantidad(): array
    {
        return UnidadMedida::query()
            ->where('uso', UsoUnidad::Cantidad)
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (UnidadMedida $unidad): array => [
                $unidad->id => $unidad->nombre.' ('.$unidad->simbolo.')',
            ])
            ->all();
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
            ->label($id === 'id' ? 'SKU interno de la variante' : 'SKU interno')
            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: 'Identificador único de esta variante para controlar su stock. El código del producto es la referencia compartida que se muestra al vender.')
            ->required()
            ->maxLength(255)
            ->distinct()
            ->scopedUnique(
                model: ProductoVariante::class,
                ignorable: fn (Get $get) => $get($id),
                ignoreRecord: false,
            );
    }

    private static function campoCondicion(string $campoId): Select
    {
        return Select::make('condicion_producto_id')
            ->label('Condición del producto')
            ->live()
            ->options(function (Get $get, ?ProductoMaestro $record) use ($campoId): array {
                $actual = $record?->variantes()->whereKey($get($campoId))->value('condicion_producto_id');

                return CondicionProducto::query()
                    ->where(fn ($query) => $query->where('is_active', true)->when($actual !== null, fn ($query) => $query->orWhereKey($actual)))
                    ->orderBy('orden')->orderBy('nombre')->pluck('nombre', 'id')->all();
            })
            ->default(fn (): mixed => CondicionProducto::query()->where('codigo', 'nuevo')->where('is_active', true)->value('id'))
            ->required()
            ->native(false)
            ->validationMessages(['required' => 'Elegí la condición del producto.', 'in' => 'Elegí una condición disponible de este negocio.'])
            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: 'Indica si esta variante es nueva, usada u otra condición. El stock y los precios corresponden a su SKU.');
    }

    private static function regenerarVariantes(Set $set, Get $get): void
    {
        $set('variantes', app(VariantesProducto::class)->combinar(
            is_array($get('opciones')) ? $get('opciones') : [],
            is_array($get('variantes')) ? $get('variantes') : [],
            $get('sku'), $get('precio_venta'), $get('precio_mayor'), $get('codigo_barras'),
            $get('precio_venta_dolar'), $get('precio_mayor_dolar'), $get('condicion_producto_id'),
            is_array($get('condiciones_variantes')) ? $get('condiciones_variantes') : [],
            $get('variante_id'), $get('codigo_repuesto'),
        ));
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

    private static function campoPrecio(string $nombre, string $etiqueta, bool $obligatorio, string $moneda, bool $conDolar): TextInput
    {
        $campo = TextInput::make($nombre)
            ->label($etiqueta)
            ->numeric()
            ->minValue(0)
            ->prefix($moneda)
            ->maxValue(99999999.99)
            ->rule('regex:/^\d{1,8}(\.\d{1,2})?$/')
            ->disabled(fn (Get $get): bool => $conDolar && filled($get($nombre.'_dolar')))
            ->saved()
            ->helperText(fn (Get $get): ?string => $conDolar && filled($get($nombre.'_dolar'))
                ? 'Se calcula al guardar con el tipo de cambio publicado.'
                : null);

        if ($obligatorio) {
            $campo->required(fn (Get $get): bool => ! $conDolar || blank($get($nombre.'_dolar')));
        } else {
            $campo->nullable();
        }

        return $campo;
    }

    private static function referencia(string $nombre, string $etiqueta, bool $conDolar, string $moneda, ?string $cotizacion): TextInput
    {
        return TextInput::make($nombre)
            ->label($etiqueta)
            ->prefix('USD')
            ->numeric()
            ->minValue(0.01)
            ->maxValue(99999999.99)
            ->rule('regex:/^\d{1,8}(\.\d{1,2})?$/')
            ->live(onBlur: true)
            ->helperText(function (mixed $state) use ($moneda, $cotizacion): string {
                if ($cotizacion === null) {
                    return 'Publicá un tipo de cambio comercial para usar una referencia.';
                }

                if (is_scalar($state) && preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $state) === 1) {
                    return 'Precio del mostrador a la cotización vigente · '.$moneda.' '.Montos::multiplicar((string) $state, $cotizacion, 2);
                }

                return 'Dejalo vacío para usar el precio fijo en '.$moneda.'.';
            })
            ->visible($conDolar);
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
                    ->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
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
}
