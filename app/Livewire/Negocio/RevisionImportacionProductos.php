<?php

namespace App\Livewire\Negocio;

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos;
use App\Catalogo\MapearColumnasProductos;
use App\Compras\Montos;
use App\Enums\TipoAtributoCategoria;
use App\Enums\UsoUnidad;
use App\Filament\Negocio\Resources\Categorias\Schemas\CategoriaForm;
use App\Filament\Negocio\Resources\Marcas\Schemas\MarcaForm;
use App\Models\Categoria;
use App\Models\CondicionProducto;
use App\Models\ImportacionProducto;
use App\Models\Marca;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RevisionImportacionProductos extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public const CATALOGOS = [
        'condicion' => 'Condición',
        'categoria' => 'Categoría', 'marca' => 'Marca', 'unidad_stock' => 'Unidad de stock', 'proveedor' => 'Proveedor',
    ];

    #[Locked]
    public int $importacionId;

    public function mount(int $importacionId): void
    {
        $this->importacionId = $importacionId;
        $this->importacion;
    }

    #[Computed]
    public function importacion(): ImportacionProducto
    {
        $usuario = auth('tenant')->user();
        abort_unless($usuario instanceof TenantUser, 403);

        return app(ImportarProductos::class)->consultar($this->importacionId, $usuario);
    }

    #[Computed]
    public function opcionesCatalogos(): array
    {
        return [
            'condicion' => CondicionProducto::query()->where(fn ($query) => $query->where('is_active', true)
                ->orWhereIn('id', ProductoVariante::query()->whereIn('sku', array_column($this->importacion->filas, 'sku'))->select('condicion_producto_id')))
                ->orderBy('orden')->orderBy('nombre')->pluck('nombre', 'nombre')->all(),
            'categoria' => Categoria::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'nombre')->all(),
            'marca' => Marca::query()->orderBy('nombre')->pluck('nombre', 'nombre')->all(),
            'unidad_stock' => UnidadMedida::query()->where('uso', UsoUnidad::Cantidad)->orderBy('nombre')->get()
                ->mapWithKeys(fn (UnidadMedida $unidad): array => [$unidad->simbolo => $unidad->nombre.' ('.$unidad->simbolo.')'])->all(),
            'proveedor' => Proveedor::query()->orderBy('nombre_empresa')->pluck('nombre_empresa', 'nombre_empresa')->all(),
        ];
    }

    public function puedeCorregir(): bool
    {
        return in_array($this->importacion->estado, ['revision', 'fallida'], true);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function filas(): Collection
    {
        $importacion = $this->importacion;
        $resueltas = collect($importacion->revision['filas'])->keyBy('fila');

        return collect($importacion->filas)->mapWithKeys(function (array $fila) use ($importacion, $resueltas): array {
            $fila = ['codigo_producto' => '', 'condicion' => '', ...$fila];
            $resuelta = $resueltas->get($fila['fila']);
            $errores = $importacion->revision['errores'][$fila['fila']] ?? [];

            return [$fila['fila'] => [
                ...$fila,
                'codigo_producto' => $resuelta['codigo_producto'] ?? $fila['codigo_producto'],
                'condicion' => $resuelta['condicion'] ?? $fila['condicion'],
                'accion' => $errores !== [] ? 'corregir' : ($resuelta['accion'] ?? 'conservar'),
                'errores' => $errores,
                'cambios' => $resuelta['cambios'] ?? [],
                'precio_menor' => $resuelta['precio_menor'] ?? $fila['precio_menor'],
                'precio_mayor' => $resuelta['precio_mayor'] ?? $fila['precio_mayor'],
                'valor_lote' => isset($resuelta['cantidad']) ? Montos::multiplicar($resuelta['cantidad'], $resuelta['costo'], 2) : null,
            ]];
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function filtrar(?string $search, array $filters): Collection
    {
        $filas = $this->filas();
        $busqueda = mb_strtolower(trim($search ?? ''));
        if ($busqueda !== '') {
            $filas = $filas->filter(fn (array $fila): bool => str_contains(mb_strtolower(implode(' ', [$fila['codigo_producto'], $fila['condicion'], $fila['sku'], $fila['nombre'], $fila['categoria'], $fila['marca'], $fila['proveedor']])), $busqueda));
        }
        if (filled($estado = $filters['accion']['value'] ?? null)) {
            $filas = $filas->where('accion', $estado);
        }

        return $filas;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, array $filters, int $page, int $recordsPerPage): LengthAwarePaginator {
                $filas = $this->filtrar($search, $filters);

                return new LengthAwarePaginator($filas->forPage($page, $recordsPerPage), $filas->count(), $recordsPerPage, $page, ['pageName' => 'revisionPage']);
            })
            ->resolveSelectedRecordsUsing(function (array $keys, bool $isTrackingDeselectedKeys, array $deselectedKeys): Collection {
                $filas = $this->filtrar($this->getTableSearch(), $this->tableFilters ?? []);

                return $isTrackingDeselectedKeys ? $filas->except($deselectedKeys) : $filas->only($keys);
            })
            ->queryStringIdentifier('revision')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->stackedOnMobile()
            ->columnManagerTriggerAction(fn (Action $action): Action => $action->extraAttributes(['class' => 'max-sm:hidden']))
            ->columns([
                TextColumn::make('fila')->label('Fila')->visibleFrom('sm'),
                TextColumn::make('producto_movil')->label('Producto')->hiddenFrom('sm')
                    ->state(fn (array $record): string => $record['nombre'])->wrap()->lineClamp(3)
                    ->description(fn (array $record): string => 'Código '.($record['codigo_producto'] ?: $record['sku']).' · '.$record['condicion'].' · Fila '.$record['fila']),
                TextColumn::make('revision_movil')->label('Revisión')->hiddenFrom('sm')->badge()
                    ->state(fn (array $record): string => $record['errores'] !== [] ? count($record['errores']).' pendiente(s)' : ($record['cambios'] !== [] ? 'Con cambios' : 'Listo'))
                    ->color(fn (array $record): string => $record['errores'] !== [] ? 'warning' : ($record['cambios'] !== [] ? 'info' : 'success'))
                    ->description(fn (array $record): string => $this->resumenMovil($record))->wrap(),
                TextColumn::make('nombre')->label('Producto')->visibleFrom('sm')->limit(65)->tooltip(fn (array $record): string => $record['nombre'])->wrap()->searchable()
                    ->description(fn (array $record): string => 'Código '.($record['codigo_producto'] ?: $record['sku']).' · SKU interno: '.$record['sku']),
                TextColumn::make('accion')->label('Revisión')->visibleFrom('sm')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'corregir' => 'Corregir', 'crear' => 'Crear', 'actualizar' => 'Actualizar', default => 'Conservar',
                })->color(fn (string $state): string => match ($state) {
                    'corregir' => 'danger', 'crear' => 'success', default => 'gray'
                })->description(fn (array $record): ?string => $record['errores'] !== [] ? count($record['errores']).' pendiente(s)' : ($record['cambios'] !== [] ? count($record['cambios']).' cambio(s) · Revisar producto' : null))
                    ->tooltip(fn (array $record): ?string => $record['errores'] === [] ? null : implode(' ', $record['errores'])),
                $this->selector('condicion'),
                $this->selector('categoria'),
                $this->selector('marca')->toggleable(isToggledHiddenByDefault: true),
                $this->selector('unidad_stock')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('precio_menor')->label('Precio menor')->visibleFrom('sm')->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('precio_mayor')->label('Precio mayor')->visibleFrom('sm')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cantidad_inicial')->label('Cantidad inicial')->visibleFrom('sm')->alignEnd()->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextColumn::make('costo_unitario')->label('Costo unitario')->visibleFrom('sm')->alignEnd()->toggleable(isToggledHiddenByDefault: true)->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextColumn::make('valor_lote')->label('Valor del lote')->visibleFrom('sm')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                $this->selector('proveedor')->toggleable(isToggledHiddenByDefault: true)->visible(fn (): bool => $this->importacion->almacen_id !== null),
            ])
            ->filters([SelectFilter::make('accion')->label('Revisión')->options([
                'corregir' => 'Con errores', 'crear' => 'Variantes nuevas', 'actualizar' => 'Se actualizarán', 'conservar' => 'Conservar existentes',
            ])])->deferFilters(false)
            ->recordActions([$this->editarFila()])
            ->headerActions([
                $this->resolverCatalogos(),
                Action::make('revalidar')->label('Actualizar revisión')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (): bool => $this->puedeCorregir())
                    ->action(function (): void {
                        app(ImportarProductos::class)->revalidar($this->importacionId, auth('tenant')->user());
                        unset($this->opcionesCatalogos);
                        $this->actualizarRevision();
                    }),
            ])
            ->toolbarActions([$this->asignarEnLote()])
            ->emptyStateHeading('No hay filas para esta consulta');
    }

    private function selector(string $campo): SelectColumn
    {
        return SelectColumn::make($campo)->label(self::CATALOGOS[$campo])->visibleFrom('sm')->native(false)->searchableOptions()
            ->options(function (array $record) use ($campo): array {
                $opciones = $this->opcionesCatalogos[$campo];
                if (filled($record[$campo]) && ! array_key_exists($record[$campo], $opciones)) {
                    $opciones[$record[$campo]] = $record[$campo].' · Revisar';
                }

                return $opciones;
            })
            ->placeholder(in_array($campo, ['marca', 'proveedor'], true) ? 'Sin especificar' : 'Elegí una opción')
            ->disabled(fn (): bool => ! $this->puedeCorregir())
            ->updateStateUsing(function (array $record, ?string $state) use ($campo): ?string {
                $this->guardar([$record['fila']], [$campo => $state]);

                return $state;
            });
    }

    /** @param array<string, mixed> $fila */
    private function resumenMovil(array $fila): string
    {
        if ($fila['errores'] === []) {
            return $fila['cambios'] !== [] ? 'Revisá los '.count($fila['cambios']).' cambios propuestos.' : 'Sin pendientes de validación.';
        }
        $pendientes = [];
        foreach (self::CATALOGOS as $campo => $etiqueta) {
            $valor = (string) $fila[$campo];
            if (blank($valor) && in_array($campo, ['marca', 'proveedor'], true)) {
                continue;
            }
            $opciones = array_keys($this->opcionesCatalogos[$campo]);
            $coincidencias = array_filter($opciones, fn (string $opcion): bool => $campo === 'unidad_stock'
                ? $opcion === $valor : mb_strtolower($opcion) === mb_strtolower($valor));
            if (count($coincidencias) !== 1) {
                $pendientes[] = mb_strtolower($etiqueta);
            }
        }

        return $pendientes !== [] ? 'Revisar '.implode(' y ', $pendientes).'.' : Str::limit($fila['errores'][0], 120);
    }

    private function editarFila(): Action
    {
        return Action::make('corregirFila')->label(fn (): string => $this->puedeCorregir() ? 'Revisar producto' : 'Ver detalle')->icon('heroicon-o-eye')->color('gray')->button()->outlined()
            ->modalHeading('Revisar producto')->slideOver()->modalWidth('3xl')
            ->disabledSchema(fn (): bool => ! $this->puedeCorregir())->modalSubmitAction(fn (Action $action): Action => $action->visible(fn (): bool => $this->puedeCorregir()))
            ->fillForm(function (array $record): array {
                $fila = ['codigo_producto' => '', 'condicion' => '', ...collect($this->importacion->filas)->firstWhere('fila', $record['fila'])];
                $fila['condicion'] = $record['condicion'];
                foreach (array_keys(self::CATALOGOS) as $campo) {
                    $valor = (string) $fila[$campo];
                    $coincidencias = array_filter(array_keys($this->opcionesCatalogos[$campo]), fn (string $opcion): bool => $campo === 'unidad_stock'
                        ? $opcion === $valor : mb_strtolower($opcion) === mb_strtolower($valor));
                    if (count($coincidencias) === 1) {
                        $fila[$campo] = (string) array_values($coincidencias)[0];
                    }
                }
                $categorias = Categoria::query()->with('atributos')->whereRaw('lower(nombre) = lower(?)', [$fila['categoria']])->get();
                $categoria = $categorias->count() === 1 ? $categorias->first() : null;
                $resuelta = collect($this->importacion->revision['filas'])->firstWhere('fila', $fila['fila']);
                $ficha = filled($fila['atributos']) ? json_decode($fila['atributos'], true) : ($resuelta['variante']['atributos_json'] ?? []);
                $fila['ficha'] = app(MapearColumnasProductos::class)->ficha($fila, (int) $categoria?->id, is_array($ficha) ? $ficha : [], app(MapearColumnasProductos::class)->atributos(), false);
                foreach ($categoria?->atributos ?? [] as $atributo) {
                    if ($atributo->tipo === TipoAtributoCategoria::Lista && isset($fila['ficha'][$atributo->clave])) {
                        $fila['ficha'][$atributo->clave] = Arr::wrap($fila['ficha'][$atributo->clave]);
                    }
                }

                return $fila;
            })
            ->schema([
                Section::make('Cambios propuestos')->description('Compará los datos actuales con los que se aplicarán al confirmar la importación. Si editás esta fila, guardala para recalcular la comparación.')
                    ->visible(fn (array $record): bool => $this->cambiosFila($record['fila']) !== [])
                    ->schema([
                        RepeatableEntry::make('cambios_propuestos')->hiddenLabel()->state(fn (array $record): array => $this->cambiosFila($record['fila']))
                            ->schema([
                                TextEntry::make('campo')->label('Campo'),
                                TextEntry::make('anterior')->label('Actual')->wrap(),
                                TextEntry::make('nuevo')->label('Al confirmar')->wrap(),
                            ])->columns(3),
                    ]),
                Section::make('Qué falta corregir')->description(fn (array $record): string => implode(' ', $this->erroresFila($record['fila'])))
                    ->visible(fn (array $record): bool => $this->erroresFila($record['fila']) !== [])->schema([]),
                Section::make('Ficha recibida del archivo')->collapsible()->collapsed()
                    ->description(function (array $record): string {
                        $opciones = app(MapearColumnasProductos::class)->opciones();

                        return collect(json_decode($record['_ficha_columnas'] ?? '{}', true))
                            ->filter(fn (string $valor): bool => $valor !== '')
                            ->map(fn (string $valor, string $campo): string => ($opciones[$campo] ?? $campo).': '.$valor)->implode(' · ');
                    })->schema([])->visible(fn (array $record): bool => filled($record['_ficha_columnas'] ?? null)),
                TextInput::make('codigo_producto')->label('Código del producto')->maxLength(255)
                    ->helperText('Compartido por las variantes Nuevo, Usado u otras del mismo producto. Deben conservar el mismo nombre, categoría, marca y unidad.'),
                TextInput::make('sku')->label('SKU interno de la variante')->maxLength(255),
                TextInput::make('nombre')->label('Nombre')->maxLength(255),
                ...collect(self::CATALOGOS)->except('proveedor')->map(fn (string $label, string $campo): Select => $this->selectorFormulario($campo, $label))->values()->all(),
                TextInput::make('codigo_barras')->label('Código de barras')->maxLength(255),
                TextInput::make('precio_menor')->label('Precio menor')->maxLength(20)->inputMode('decimal'),
                TextInput::make('precio_mayor')->label('Precio mayor')->maxLength(20)->inputMode('decimal'),
                TextInput::make('cantidad_inicial')->label('Cantidad inicial')->maxLength(20)->inputMode('decimal')
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextInput::make('costo_unitario')->label('Costo unitario')->maxLength(20)->inputMode('decimal')
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                $this->selectorFormulario('proveedor', 'Proveedor')->visible(fn (): bool => $this->importacion->almacen_id !== null),
                Section::make('Ficha técnica')->description('Campos de la categoría elegida. Las unidades y las opciones corresponden a su configuración.')
                    ->schema(fn (Get $get): array => $this->camposFicha($get('categoria'))),
            ])->modalSubmitActionLabel('Guardar y validar')
            ->action(function (array $record, array $data): void {
                $datos = Arr::only($data, ArchivoProductos::COLUMNAS);
                $datos['atributos'] = json_encode((object) ($data['ficha'] ?? []), JSON_THROW_ON_ERROR);
                $this->guardar([$record['fila']], $datos);
            });
    }

    private function selectorFormulario(string $campo, string $label): Select
    {
        $selector = Select::make($campo)->label($label)
            ->options(fn (): array => $this->opcionesCatalogos[$campo])->searchable()->native(false)->live()
            ->hint(fn (mixed $state): ?string => $this->avisoCatalogo($campo, $state) ? (blank($state) ? 'Falta seleccionar' : 'Sin coincidencia') : null)
            ->hintColor('warning')
            ->prefixIcon(fn (mixed $state): ?string => $this->avisoCatalogo($campo, $state) ? 'heroicon-o-exclamation-triangle' : null)
            ->prefixIconColor('warning')
            ->helperText(fn (mixed $state): ?string => $this->avisoCatalogo($campo, $state))
            ->validationMessages(['in' => match ($campo) {
                'categoria' => 'Elegí una categoría activa del negocio o creala con el botón +.',
                'marca' => 'Elegí una marca del negocio o creala con el botón +.',
                'condicion' => 'Elegí una condición disponible del negocio o creala con el botón +.',
                default => 'Elegí una unidad de stock del negocio.',
            }])
            ->afterStateUpdated(function (Set $set) use ($campo): void {
                if ($campo === 'categoria') {
                    $set('ficha', []);
                }
            });
        if (! in_array($campo, ['marca', 'categoria', 'condicion'], true)) {
            return $selector;
        }
        $modelo = match ($campo) {
            'marca' => Marca::class, 'condicion' => CondicionProducto::class, default => Categoria::class,
        };
        $modeloPermiso = $campo === 'condicion' ? ProductoMaestro::class : $modelo;
        if ($campo === 'categoria') {
            $selector->createOptionForm(fn (Schema $schema): Schema => CategoriaForm::configure($schema->model(Categoria::class)));
        } elseif ($campo === 'condicion') {
            $selector->createOptionForm([self::nombreCondicion()]);
        } else {
            $selector->createOptionForm([MarcaForm::nombre()]);
        }

        return $selector->createOptionUsing(function (array $data, Schema $schema) use ($campo, $modelo, $modeloPermiso): string {
            $usuario = auth('tenant')->user();
            $importacion = app(ImportarProductos::class)->consultar($this->importacionId, $usuario);
            app(ImportarProductos::class)->autorizar($usuario, $importacion->almacen_id);
            abort_unless(in_array($importacion->estado, ['revision', 'fallida'], true), 403);
            Gate::forUser($usuario)->authorize('create', $modeloPermiso);
            $registro = DB::transaction(function () use ($campo, $modelo, $data, $schema): Model {
                $datos = $campo === 'condicion'
                    ? ['nombre' => trim($data['nombre']), 'codigo' => self::codigoCondicion($data['nombre'])]
                    : Arr::only($data, $campo === 'marca' ? ['nombre'] : ['nombre', 'is_active', 'sin_devolucion_comercial']);
                $registro = $modelo::query()->create($datos);
                if ($campo === 'categoria') {
                    $schema->model($registro)->saveRelationships();
                }

                app(ImportarProductos::class)->revalidar($this->importacionId, auth('tenant')->user());

                return $registro;
            });
            unset($this->opcionesCatalogos);
            unset($this->importacion);
            $this->flushCachedTableRecords();
            $this->dispatch('importacion-corregida', id: $this->importacionId);
            $this->forceRender();

            return $registro->nombre;
        })->createOptionAction(fn (Action $action): Action => $action
            ->authorize(fn (): bool => $this->puedeCorregir() && Gate::forUser(auth('tenant')->user())->allows('create', $modeloPermiso))
            ->visible(fn (): bool => $this->puedeCorregir() && Gate::forUser(auth('tenant')->user())->allows('create', $modeloPermiso))
            ->modalHeading(match ($campo) {
                'marca' => 'Nueva marca', 'condicion' => 'Nueva condición', default => 'Nueva categoría'
            })
            ->fillForm(fn (Select $component): array => [
                'nombre' => $component->getState(),
                ...($campo === 'categoria' ? ['is_active' => true, 'sin_devolucion_comercial' => false, 'atributos' => []] : []),
            ])
            ->modalDescription('El registro se agrega al catálogo al crearlo. Guardá la fila para aplicar la selección a esta revisión.')
            ->stickyModalHeader()->stickyModalFooter());
    }

    private static function codigoCondicion(string $nombre): string
    {
        return rtrim(Str::substr(Str::slug(trim($nombre)), 0, 50), '-');
    }

    private static function nombreCondicion(): TextInput
    {
        return TextInput::make('nombre')->label('Nombre')->placeholder('Ej. Reacondicionado')
            ->required()->maxLength(255)->dehydrateStateUsing(fn (string $state): string => trim($state))
            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $nombre = trim((string) $value);
                $codigo = self::codigoCondicion($nombre);
                if ($codigo === '') {
                    $fail('Ingresá un nombre con letras o números.');

                    return;
                }
                if (CondicionProducto::query()->where(fn ($query) => $query->whereRaw('lower(nombre) = lower(?)', [$nombre])->orWhere('codigo', $codigo))->exists()) {
                    $fail('Ya existe una condición equivalente. Elegí la existente o usá otro nombre.');
                }
            }]);
    }

    private function avisoCatalogo(string $campo, mixed $valor): ?string
    {
        if (blank($valor)) {
            return match ($campo) {
                'categoria' => 'Elegí una categoría del negocio para este producto.',
                'unidad_stock' => 'Elegí la unidad con la que se contará el stock.',
                'condicion' => 'Elegí Nuevo, Usado u otra condición. En un SKU existente, dejarla vacía conserva su condición.',
                default => null,
            };
        }
        if (array_key_exists((string) $valor, $this->opcionesCatalogos[$campo])) {
            return null;
        }

        $crear = $this->puedeCorregir() && $this->puedeCrearCatalogo($campo);

        return 'Del Excel: «'.$valor.'». '.match ($campo) {
            'categoria' => $crear ? 'Elegí una categoría del negocio o creala con +.' : 'Elegí una categoría existente del negocio.',
            'marca' => ($crear ? 'Elegí una marca del negocio o creala con +.' : 'Elegí una marca existente del negocio.').' También podés dejarla sin especificar.',
            'proveedor' => 'Elegí un proveedor del negocio o dejalo sin especificar.',
            'condicion' => $crear ? 'Elegí una condición del negocio o creala con +.' : 'Elegí una condición disponible del negocio.',
            default => 'Elegí una unidad de stock del negocio.',
        };
    }

    /** @return list<string> */
    private function erroresFila(int $numero): array
    {
        return $this->importacion->revision['errores'][$numero] ?? [];
    }

    /** @return list<array{campo: string, anterior: string, nuevo: string}> */
    private function cambiosFila(int $numero): array
    {
        return collect($this->importacion->revision['filas'])->firstWhere('fila', $numero)['cambios'] ?? [];
    }

    private function camposFicha(?string $nombre): array
    {
        $categorias = Categoria::query()->with('atributos.unidadMedida')->where('is_active', true)->whereRaw('lower(nombre) = lower(?)', [$nombre ?? ''])->get();
        if ($categorias->count() !== 1) {
            return [];
        }

        return $categorias->first()->atributos->map(function ($atributo) {
            $campo = $atributo->tipo === TipoAtributoCategoria::Lista
                ? Select::make('ficha.'.$atributo->clave)->multiple()->options(array_combine($atributo->opciones, $atributo->opciones))->native(false)
                : TextInput::make('ficha.'.$atributo->clave)->maxLength(255);
            if ($atributo->tipo === TipoAtributoCategoria::Decimal) {
                $campo->maxLength(null)->numeric()->minValue(0)->suffix($atributo->unidadMedida?->simbolo);
            }

            return $campo->label($atributo->etiqueta)->required($atributo->obligatorio);
        })->all();
    }

    private function resolverCatalogos(): Action
    {
        return Action::make('resolverCatalogos')->label('Resolver catálogos')->icon('heroicon-o-squares-plus')->color('gray')->slideOver()
            ->visible(fn (): bool => $this->puedeCorregir())->modalHeading('Resolver un valor para toda la carga')
            ->modalDescription('Por ejemplo, relacioná una marca del Excel con la del negocio y se corregirán todas sus filas. Crear un registro agrega ese dato al catálogo, aunque luego no confirmes la carga.')
            ->schema([
                Select::make('campo')->label('Catálogo')->options(self::CATALOGOS)->required()->native(false)->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('origen', null);
                        $set('destino', null);
                        $set('crear', false);
                    }),
                Select::make('origen')->label('Valor del archivo')->required()->searchable()->native(false)->live()
                    ->options(fn (Get $get): array => ! isset(self::CATALOGOS[$get('campo') ?? '']) ? [] : collect($this->importacion->filas)
                        ->pluck($get('campo'))->filter(fn ($valor): bool => filled($valor))->countBy()->map(fn (int $cantidad, string $valor): string => $valor.' · '.$cantidad.' fila(s)')->all()),
                Toggle::make('crear')->label('Crear en el catálogo con el nombre del archivo')->live()
                    ->visible(fn (Get $get): bool => $get('campo') !== 'condicion' && $this->puedeCrearCatalogo($get('campo'))),
                Select::make('destino')->label('Usar este valor del negocio')->options(fn (Get $get): array => $this->opcionesCatalogos[$get('campo')] ?? [])
                    ->placeholder('Sin especificar')->searchable()->native(false)->visible(fn (Get $get): bool => ! $get('crear'))
                    ->required(fn (Get $get): bool => ! $get('crear') && ! in_array($get('campo'), ['marca', 'proveedor'], true)),
            ])->modalSubmitActionLabel('Aplicar a todas las coincidencias')
            ->action(function (array $data): void {
                app(ImportarProductos::class)->resolverCatalogo($this->importacionId, $data['campo'], $data['origen'], $data['destino'] ?? null, (bool) ($data['crear'] ?? false), auth('tenant')->user());
                unset($this->opcionesCatalogos);
                $this->actualizarRevision();
            });
    }

    private function puedeCrearCatalogo(?string $campo): bool
    {
        $modelo = match ($campo) {
            'marca' => Marca::class, 'categoria' => Categoria::class, 'proveedor' => Proveedor::class, 'condicion' => ProductoMaestro::class, default => null
        };

        return $modelo !== null && Gate::forUser(auth('tenant')->user())->allows('create', $modelo);
    }

    private function asignarEnLote(): BulkAction
    {
        return BulkAction::make('asignarCampo')->label('Asignar a seleccionadas')->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->puedeCorregir())->modalHeading('Asignar un valor a las filas seleccionadas')
            ->schema([
                Select::make('campo')->label('Campo')->required()->native(false)->live()
                    ->options(fn (): array => $this->importacion->almacen_id === null ? array_diff_key(self::CATALOGOS, ['proveedor' => true]) : self::CATALOGOS)
                    ->afterStateUpdated(function (Set $set): void {
                        $set('valor', null);
                        $set('quitar', false);
                    }),
                Toggle::make('quitar')->label('Dejar sin especificar')->live()
                    ->visible(fn (Get $get): bool => in_array($get('campo'), ['marca', 'proveedor'], true)),
                Select::make('valor')->label('Asignar')->searchable()->native(false)
                    ->options(fn (Get $get): array => $this->opcionesCatalogos[$get('campo')] ?? [])
                    ->required(fn (Get $get): bool => ! in_array($get('campo'), ['marca', 'proveedor'], true) || ! $get('quitar'))
                    ->visible(fn (Get $get): bool => ! in_array($get('campo'), ['marca', 'proveedor'], true) || ! $get('quitar')),
            ])->modalSubmitActionLabel('Aplicar y validar')->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $campo = $data['campo'];
                if (! array_key_exists($campo, self::CATALOGOS)) {
                    throw ValidationException::withMessages(['campo' => 'Elegí un campo válido.']);
                }
                $quitar = ($data['quitar'] ?? false) && in_array($campo, ['marca', 'proveedor'], true);
                $this->guardar($records->pluck('fila')->all(), [$campo => $quitar ? '' : $data['valor']]);
            });
    }

    private function guardar(array $filas, array $cambios): void
    {
        app(ImportarProductos::class)->corregirFilas($this->importacionId, $filas, $cambios, auth('tenant')->user());
        $this->actualizarRevision();
    }

    private function actualizarRevision(): void
    {
        unset($this->importacion);
        $this->flushCachedTableRecords();
        $this->dispatch('importacion-corregida', id: $this->importacionId);
        Notification::make()->title('Corrección guardada')->success()->send();
    }

    public function render(): View
    {
        return view('livewire.negocio.revision-importacion-productos');
    }
}
