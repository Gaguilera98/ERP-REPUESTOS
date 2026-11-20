<?php

namespace App\Livewire\Negocio;

use App\Catalogo\ImportarProductos;
use App\Compras\Montos;
use App\Enums\UsoUnidad;
use App\Models\Categoria;
use App\Models\ImportacionProducto;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
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
            $resuelta = $resueltas->get($fila['fila']);
            $errores = $importacion->revision['errores'][$fila['fila']] ?? [];

            return [$fila['fila'] => [
                ...$fila,
                'accion' => $errores !== [] ? 'corregir' : ($resuelta['accion'] ?? 'conservar'),
                'errores' => $errores,
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
            $filas = $filas->filter(fn (array $fila): bool => str_contains(mb_strtolower(implode(' ', [$fila['sku'], $fila['nombre'], $fila['categoria'], $fila['marca'], $fila['proveedor']])), $busqueda));
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
            ->queryStringIdentifier('revision')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('fila')->label('Fila'),
                TextColumn::make('nombre')->label('Producto')->wrap()->searchable()
                    ->description(fn (array $record): HtmlString => new HtmlString(
                        '<div>'.e($record['sku']).'</div>'.($record['errores'] === [] ? '' : '<div class="mt-1 text-danger-600 dark:text-danger-400">'.e(implode(' ', $record['errores'])).'</div>'),
                    )),
                TextColumn::make('accion')->label('Revisión')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'corregir' => 'Corregir', 'crear' => 'Crear', 'actualizar' => 'Actualizar', default => 'Conservar',
                })->color(fn (string $state): string => match ($state) {
                    'corregir' => 'danger', 'crear' => 'success', default => 'gray'
                }),
                $this->selector('categoria'),
                $this->selector('marca')->toggleable(isToggledHiddenByDefault: true),
                $this->selector('unidad_stock')->toggleable(),
                TextColumn::make('precio_menor')->label('Precio menor')->alignEnd()->toggleable(),
                TextColumn::make('precio_mayor')->label('Precio mayor')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cantidad_inicial')->label('Cantidad inicial')->alignEnd()->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextColumn::make('costo_unitario')->label('Costo unitario')->alignEnd()->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextColumn::make('valor_lote')->label('Valor del lote')->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                $this->selector('proveedor')->visible(fn (): bool => $this->importacion->almacen_id !== null),
            ])
            ->filters([SelectFilter::make('accion')->label('Revisión')->options([
                'corregir' => 'Con errores', 'crear' => 'Productos nuevos', 'actualizar' => 'Se actualizarán', 'conservar' => 'Conservar existentes',
            ])])->deferFilters(false)
            ->recordActions([$this->editarFila()])
            ->toolbarActions([$this->asignarEnLote()])
            ->emptyStateHeading('No hay filas para esta consulta');
    }

    private function selector(string $campo): SelectColumn
    {
        return SelectColumn::make($campo)->label(self::CATALOGOS[$campo])->native(false)->searchableOptions()
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

    private function editarFila(): Action
    {
        return Action::make('corregirFila')->label('Corregir fila')->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn (): bool => $this->puedeCorregir())->modalHeading('Corregir datos de la fila')
            ->fillForm(function (array $record): array {
                return collect($this->importacion->filas)->firstWhere('fila', $record['fila']);
            })
            ->schema([
                TextInput::make('sku')->label('SKU')->maxLength(255),
                TextInput::make('nombre')->label('Nombre')->maxLength(255),
                ...collect(self::CATALOGOS)->except('proveedor')->map(fn (string $label, string $campo): Select => Select::make($campo)->label($label)
                    ->options(fn (): array => $this->opcionesCatalogos[$campo])->searchable()->native(false))->values()->all(),
                TextInput::make('codigo_barras')->label('Código de barras')->maxLength(255),
                TextInput::make('precio_menor')->label('Precio menor')->maxLength(20)->inputMode('decimal'),
                TextInput::make('precio_mayor')->label('Precio mayor')->maxLength(20)->inputMode('decimal'),
                TextInput::make('cantidad_inicial')->label('Cantidad inicial')->maxLength(20)->inputMode('decimal')
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                TextInput::make('costo_unitario')->label('Costo unitario')->maxLength(20)->inputMode('decimal')
                    ->visible(fn (): bool => $this->importacion->almacen_id !== null),
                Select::make('proveedor')->label('Proveedor')->options(fn (): array => $this->opcionesCatalogos['proveedor'])
                    ->searchable()->native(false)->visible(fn (): bool => $this->importacion->almacen_id !== null),
                Textarea::make('atributos')->label('Ficha técnica en JSON')->maxLength(10000)->rows(3),
            ])->modalSubmitActionLabel('Guardar y validar')
            ->action(fn (array $record, array $data) => $this->guardar([$record['fila']], $data));
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
