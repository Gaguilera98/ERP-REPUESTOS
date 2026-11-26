<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos as Importador;
use App\Catalogo\MapearColumnasProductos;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\Categoria;
use App\Models\ImportacionProducto;
use App\Models\NegocioConfiguracion;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportarProductos extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ProductoMaestroResource::class;

    protected string $view = 'filament.negocio.resources.producto-maestros.pages.importar-productos';

    public ?array $data = [];

    public ?array $columnasData = [];

    #[Locked]
    public ?array $archivoPendiente = null;

    #[Locked]
    public array $opcionesPendientes = [];

    #[Url]
    public bool $inventario = false;

    #[Url(as: 'importacion')]
    #[Locked]
    public ?int $importacionId = null;

    #[Locked]
    public ?string $versionRevision = null;

    public static function canAccess(array $parameters = []): bool
    {
        $usuario = auth('tenant')->user();

        return $usuario?->can('ViewAny:ProductoMaestro') && $usuario?->can('Importar:Productos');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['inventario' => $this->inventario, 'fecha_inventario' => today()->format('Y-m-d'), 'actualizar_existentes' => false]);
        if ($this->importacionId !== null) {
            $this->getImportacion();
        }
    }

    public function getTitle(): string
    {
        return $this->inventario ? 'Cargar inventario inicial' : 'Importar productos';
    }

    public function getBreadcrumb(): string
    {
        return 'Importar';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Subir Excel')->description('Usá una plantilla o tu propio Excel. Podrás relacionar sus columnas antes de revisar. Precios y costos en '.$this->moneda().'. Hasta '.ArchivoProductos::MAX_FILAS.' filas.')
                ->schema([
                    FileUpload::make('archivo')->label('Archivo de productos')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->validationMessages([
                            'required' => 'Seleccioná un archivo Excel (.xlsx) antes de revisar.',
                            'mimetypes' => 'El archivo debe ser un Excel con extensión .xlsx.',
                            'mimes' => 'El archivo debe ser un Excel con extensión .xlsx.',
                            'max' => 'El archivo no puede superar los 5 MB.',
                            'uploaded' => 'No se pudo subir el archivo. Volvé a seleccionarlo e intentá otra vez.',
                        ])
                        ->maxSize(5120)->columnSpanFull(),
                    Toggle::make('actualizar_existentes')->label('Actualizar productos existentes por SKU')
                        ->helperText('Activado, puede reemplazar nombre, marca, condición, precios, código de barras y ficha técnica de los SKU encontrados. Condición vacía conserva la actual. El código del producto no se cambia por Excel. Revisá los cambios antes de confirmar. Apagado, conservan sus datos.')
                        ->visible(fn (): bool => auth('tenant')->user()->can('Update:ProductoMaestro')),
                    Toggle::make('inventario')->label('Cargar existencias iniciales')->live()
                        ->helperText('Para mercadería que ya tenés. Requiere almacén, fecha y al menos una fila con cantidad y costo válidos. Podés incluir productos sin stock dejando cantidad, costo y proveedor vacíos.')
                        ->disabled(fn (): bool => ! auth('tenant')->user()->can('Cargar:InventarioInicial')),
                    Select::make('almacen_id')->label('Almacén que tiene la mercadería')
                        ->validationMessages(['required' => 'Elegí el almacén para cargar las existencias iniciales.', 'in' => 'Elegí un almacén disponible entre tus sucursales autorizadas.'])
                        ->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->required(fn (Get $get): bool => (bool) $get('inventario'))->visible(fn (Get $get): bool => (bool) $get('inventario'))
                        ->searchable()->native(false),
                    DatePicker::make('fecha_inventario')->label('Fecha del inventario')->native(false)->displayFormat('d/m/Y')
                        ->validationMessages(['required' => 'Elegí la fecha de las existencias iniciales.', 'date' => 'Ingresá una fecha válida.', 'before_or_equal' => 'La fecha del inventario no puede ser futura.'])
                        ->maxDate(today())->required(fn (Get $get): bool => (bool) $get('inventario'))
                        ->visible(fn (Get $get): bool => (bool) $get('inventario')),
                ])->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('plantilla')->label('Descargar plantilla')->color('gray')->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Preparar plantilla')->modalSubmitActionLabel('Descargar Excel')
            ->schema([Select::make('categorias')->label('Incluir ficha técnica de estas categorías')->multiple()->searchable()->native(false)->live()
                ->options(fn (): array => Categoria::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all())
                ->helperText('Podés elegir una o varias. Sus atributos se incluyen automáticamente y se actualizan en cada descarga.'),
                View::make('filament.negocio.resources.producto-maestros.pages.vista-previa-plantilla')
                    ->viewData(fn (Get $get): array => ['categorias' => Categoria::query()->where('is_active', true)
                        ->whereIn('id', $get('categorias') ?? [])->with('atributos.unidadMedida')->orderBy('nombre')->get()]),
                CheckboxList::make('campos')->label('Columnas del catálogo y del inventario')->columns(2)
                    ->options(array_diff_key(MapearColumnasProductos::CAMPOS, ['atributos' => true]))
                    ->default(array_values(array_diff(ArchivoProductos::COLUMNAS, ['atributos'])))
                    ->helperText('El SKU interno siempre se incluye. Repetí el código del producto para agrupar sus variantes y elegí una condición por fila. Los datos que falten se pueden completar durante la revisión.'),
            ])
            ->action(fn (array $data): StreamedResponse => $this->descargarPlantilla($data['categorias'] ?? [], $data['campos'] ?? []))];
    }

    public function moneda(): string
    {
        return NegocioConfiguracion::codigoMoneda();
    }

    public function revisar(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $archivo = $data['archivo'];
        if (! $archivo instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['archivo' => 'Subí el Excel de productos.']);
        }
        $local = tempnam(sys_get_temp_dir(), 'productos-');
        try {
            file_put_contents($local, $archivo->get());
            $contenido = app(ArchivoProductos::class)->inspeccionar($local);
        } finally {
            unlink($local);
        }
        $this->archivoPendiente = $contenido;
        $this->opcionesPendientes = [...array_diff_key($data, ['archivo' => true]), 'nombre_archivo' => $archivo->getClientOriginalName()];
        $this->columnasForm->fill(['mapa' => app(MapearColumnasProductos::class)->sugerir($contenido['columnas'])]);
        if ($contenido['columnas'] === ArchivoProductos::COLUMNAS) {
            $this->relacionarColumnas();
        }
    }

    public function columnasForm(Schema $schema): Schema
    {
        $opciones = ['ignorar' => 'No importar esta columna', ...app(MapearColumnasProductos::class)->opciones()];

        return $schema->statePath('columnasData')->components([
            Section::make('Relacionar columnas')->description('Comprobá las sugerencias. Cada campo se puede elegir una vez. Marcá explícitamente las columnas que no querés importar.')
                ->schema(collect($this->archivoPendiente['columnas'] ?? [])->map(fn (string $columna, int $indice): Select => Select::make('mapa.'.$indice)
                    ->label($columna)->helperText('Ejemplo: '.mb_substr(implode(' · ', array_slice(array_filter(array_column(array_column($this->archivoPendiente['filas'], 'valores'), $indice), fn (string $valor): bool => $valor !== ''), 0, 2)), 0, 160))
                    ->options($opciones)->required()->searchable()->native(false)->live()
                    ->validationMessages([
                        'required' => 'Relacioná la columna «'.$columna.'» con un campo del sistema o elegí No importar esta columna.',
                        'in' => 'Elegí un campo disponible del sistema o indicá que esta columna no se importa.',
                    ]))->all())->columns(2),
        ]);
    }

    public function relacionarColumnas(): void
    {
        abort_unless(static::canAccess() && $this->archivoPendiente !== null, 403);
        $mapa = $this->columnasForm->getState()['mapa'];
        $filas = app(MapearColumnasProductos::class)->aplicar($this->archivoPendiente, $mapa);
        $data = $this->opcionesPendientes;
        $conInventario = (bool) ($data['inventario'] ?? false);
        $importacion = app(Importador::class)->preparar(
            $filas, $data['nombre_archivo'], $conInventario ? (int) $data['almacen_id'] : null,
            $conInventario ? $data['fecha_inventario'] : null, (bool) ($data['actualizar_existentes'] ?? false), auth('tenant')->user(),
        );
        $this->importacionId = $importacion->id;
        $this->archivoPendiente = null;
        $this->opcionesPendientes = [];
        unset($this->importacion);
    }

    #[Computed]
    public function importacion(): ?ImportacionProducto
    {
        if ($this->importacionId === null) {
            return null;
        }
        $importacion = $this->getImportacion();
        $this->versionRevision = $importacion->huellaRevision();

        return $importacion;
    }

    private function getImportacion(): ImportacionProducto
    {
        abort_if($this->importacionId === null, 404);

        return app(Importador::class)->consultar($this->importacionId, auth('tenant')->user());
    }

    public function confirmar(): void
    {
        $importacion = $this->getImportacion();
        app(Importador::class)->confirmar($importacion->id, auth('tenant')->user(), $this->versionRevision);
        unset($this->importacion);
        Notification::make()->title('Importación enviada')->body('Podés seguir trabajando y consultar el resultado aquí.')->success()->send();
    }

    public function confirmarAction(): Action
    {
        return Action::make('confirmar')->label('Confirmar importación')->icon('heroicon-o-check')
            ->requiresConfirmation()->modalHeading('Confirmar los datos revisados')
            ->modalDescription('Se aplicarán los cambios del catálogo y los lotes iniciales de esta revisión. El ingreso quedará registrado en el Kardex.')
            ->action(fn () => $this->confirmar());
    }

    public function soloProductosAction(): Action
    {
        return Action::make('soloProductos')->label('Continuar solo con productos')->color('gray')
            ->visible(fn (): bool => $this->importacionId !== null && $this->importacion?->almacen_id !== null
                && in_array($this->importacion->estado, ['revision', 'fallida'], true)
                && ! Importador::contieneDatosDeExistencias($this->importacion->filas))
            ->requiresConfirmation()->modalHeading('¿Continuar sin cargar stock?')
            ->modalDescription('Se conservan las filas y todas tus correcciones. Esta carga pasará a solo productos y no ingresará existencias. Después podrás revisar y confirmar.')
            ->modalSubmitActionLabel('Continuar solo con productos')
            ->action(function (): void {
                app(Importador::class)->continuarSoloProductos($this->getImportacion()->id, auth('tenant')->user());
                $this->inventario = false;
                unset($this->importacion);
                $this->resetValidation();
                Notification::make()->title('Continuás solo con productos')->body('Conservamos tus correcciones. Revisá y confirmá cuando esté todo listo.')->success()->send();
            });
    }

    public function faltanExistenciasEnArchivo(): bool
    {
        if (! ($this->opcionesPendientes['inventario'] ?? false) || $this->archivoPendiente === null) {
            return false;
        }
        $mapa = $this->columnasData['mapa'] ?? [];
        $cantidad = array_search('cantidad_inicial', $mapa, true);
        $costo = array_search('costo_unitario', $mapa, true);

        return $cantidad === false || $costo === false || ! collect($this->archivoPendiente['filas'])->contains(function (array $fila) use ($cantidad, $costo): bool {
            return filled($fila['valores'][$cantidad] ?? null) && filled($fila['valores'][$costo] ?? null);
        });
    }

    public function relacionarSoloProductos(): void
    {
        abort_unless(static::canAccess() && $this->archivoPendiente !== null, 403);
        $mapa = $this->columnasForm->getState()['mapa'];
        $filas = app(MapearColumnasProductos::class)->aplicar($this->archivoPendiente, $mapa);
        if (Importador::contieneDatosDeExistencias($filas)) {
            throw ValidationException::withMessages(['archivo' => 'El archivo tiene cantidades, costos o proveedores. Revisalos antes de continuar solo con productos.']);
        }
        $this->opcionesPendientes['inventario'] = false;
        $this->inventario = false;
        $this->relacionarColumnas();
    }

    public function nuevaCarga(): void
    {
        $this->archivoPendiente = null;
        $this->opcionesPendientes = [];
        $this->importacionId = null;
        $this->versionRevision = null;
        unset($this->importacion);
        $this->form->fill(['inventario' => $this->inventario, 'fecha_inventario' => today()->format('Y-m-d'), 'actualizar_existentes' => false]);
        $this->resetValidation();
    }

    public function verImportacion(int $id): void
    {
        $this->importacionId = $id;
        unset($this->importacion);
        $this->getImportacion();
    }

    #[On('importacion-corregida')]
    public function actualizarRevision(int $id): void
    {
        if ($this->importacionId === $id) {
            unset($this->importacion);
        }
    }

    public function table(Table $table): Table
    {
        $usuario = auth('tenant')->user();

        return $table->query(ImportacionProducto::query()->where('user_id', $usuario->id)
            ->where(fn ($query) => $query->whereNull('almacen_id')->orWhereIn('almacen_id', app(AccesoSucursales::class)->almacenes($usuario)->select('almacenes.id'))))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('codigo')->label('Carga')->state(fn (ImportacionProducto $record): string => $record->codigo()),
                TextColumn::make('archivo')->label('Archivo')->searchable()->wrap(),
                TextColumn::make('almacen.nombre')->label('Almacén')->placeholder('Solo catálogo'),
                TextColumn::make('estado')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'completada' => 'Completada', 'en_cola' => 'En proceso', 'fallida' => 'Requiere revisión', default => 'Borrador',
                })->color(fn (string $state): string => match ($state) {
                    'completada' => 'success', 'en_cola' => 'info', 'fallida' => 'danger', default => 'warning'
                }),
                TextColumn::make('created_at')->label('Fecha de carga')->dateTime('d/m/Y H:i'),
            ])->recordActions([
                Action::make('ver')->label('Revisar')->icon('heroicon-o-eye')->action(fn (ImportacionProducto $record) => $this->verImportacion($record->id)),
                Action::make('eliminarBorrador')->label('Eliminar borrador')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (ImportacionProducto $record): bool => in_array($record->estado, ['revision', 'fallida'], true) && $record->completada_at === null)
                    ->requiresConfirmation()->modalHeading('Eliminar borrador de importación')
                    ->modalDescription('Se eliminarán esta carga y sus filas de revisión. Las marcas, categorías y otros datos creados en el catálogo se conservarán. Podés volver a subir el Excel después.')
                    ->modalSubmitActionLabel('Eliminar borrador')
                    ->action(function (ImportacionProducto $record): void {
                        app(Importador::class)->eliminarBorrador($record->id, auth('tenant')->user());
                        $this->flushCachedTableRecords();
                        Notification::make()->title('Borrador eliminado')->success()->send();
                    }),
            ])
            ->paginated([10, 25])->emptyStateHeading('Todavía no hay importaciones');
    }

    public function descargarPlantilla(array $categorias = [], ?array $campos = null): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $moneda = $this->moneda();
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla-');
        try {
            app(ArchivoProductos::class)->plantilla($ruta, $moneda, $categorias, $campos);
        } catch (\Throwable $exception) {
            unlink($ruta);
            throw $exception;
        }

        return response()->streamDownload(function () use ($ruta): void {
            try {
                readfile($ruta);
            } finally {
                unlink($ruta);
            }
        }, 'plantilla-productos.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function descargarErrores(): StreamedResponse
    {
        $importacion = $this->getImportacion();
        $ruta = tempnam(sys_get_temp_dir(), 'pendientes-');
        try {
            app(ArchivoProductos::class)->pendientes($ruta, $importacion->filas, $importacion->revision['errores'], $importacion->codigo());
        } catch (\Throwable $exception) {
            unlink($ruta);
            throw $exception;
        }

        return response()->streamDownload(function () use ($ruta): void {
            try {
                readfile($ruta);
            } finally {
                unlink($ruta);
            }
        }, 'productos-por-completar.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
