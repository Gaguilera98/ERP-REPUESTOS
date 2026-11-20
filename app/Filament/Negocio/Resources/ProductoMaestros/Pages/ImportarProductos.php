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
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
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
                        ->maxSize(5120)->columnSpanFull(),
                    Toggle::make('actualizar_existentes')->label('Actualizar productos existentes por SKU')
                        ->helperText('Activado, puede reemplazar nombre, marca, precios, código de barras y ficha técnica de los SKU encontrados. Revisá los cambios antes de confirmar. Apagado, conservan sus datos. Los SKU nuevos se crean en ambos casos.')
                        ->visible(fn (): bool => auth('tenant')->user()->can('Update:ProductoMaestro')),
                    Toggle::make('inventario')->label('Cargar existencias iniciales')->live()
                        ->helperText('Para mercadería que ya tenés. Requiere almacén, fecha y al menos una fila con cantidad y costo válidos. Podés incluir productos sin stock dejando cantidad, costo y proveedor vacíos.')
                        ->disabled(fn (): bool => ! auth('tenant')->user()->can('Cargar:InventarioInicial')),
                    Select::make('almacen_id')->label('Almacén que tiene la mercadería')
                        ->options(fn (): array => app(AccesoSucursales::class)->almacenes(auth('tenant')->user())->orderBy('nombre')->pluck('nombre', 'id')->all())
                        ->required(fn (Get $get): bool => (bool) $get('inventario'))->visible(fn (Get $get): bool => (bool) $get('inventario'))
                        ->searchable()->native(false),
                    DatePicker::make('fecha_inventario')->label('Fecha del inventario')->native(false)->displayFormat('d/m/Y')
                        ->maxDate(today())->required(fn (Get $get): bool => (bool) $get('inventario'))
                        ->visible(fn (Get $get): bool => (bool) $get('inventario')),
                ])->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('plantilla')->label('Descargar plantilla')->color('gray')->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Preparar plantilla')->modalSubmitActionLabel('Descargar Excel')
            ->schema([Select::make('categorias')->label('Incluir ficha técnica de estas categorías')->multiple()->searchable()->native(false)
                ->options(fn (): array => Categoria::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all())
                ->helperText('Sin categorías, se descarga la plantilla básica. Podés mezclar productos de varias categorías en una hoja.'),
                CheckboxList::make('campos')->label('Columnas del catálogo y del inventario')->columns(2)
                    ->options(array_diff_key(MapearColumnasProductos::CAMPOS, ['atributos' => true]))
                    ->default(array_values(array_diff(ArchivoProductos::COLUMNAS, ['atributos'])))
                    ->helperText('El SKU siempre se incluye. Los campos obligatorios que falten se deberán completar durante la revisión.'),
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
                    ->options($opciones)->required()->searchable()->native(false))->all())->columns(2),
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
        $opciones = app(MapearColumnasProductos::class)->opciones();
        $adicionales = collect($importacion->filas)->flatMap(fn (array $fila): array => array_keys(json_decode($fila['_ficha_columnas'] ?? '{}', true)))->unique()->values()->all();

        return response()->streamDownload(function () use ($importacion, $opciones, $adicionales): void {
            $ruta = tempnam(sys_get_temp_dir(), 'errores-');
            $writer = new Writer;
            try {
                $writer->openToFile($ruta);
                $writer->getCurrentSheet()->setName('Productos');
                $writer->addRow(ArchivoProductos::filaDeTexto([...ArchivoProductos::COLUMNAS, ...array_map(fn (string $campo): string => $opciones[$campo] ?? $campo, $adicionales)]));
                foreach ($importacion->filas as $fila) {
                    if (isset($importacion->revision['errores'][$fila['fila']])) {
                        $ficha = json_decode($fila['_ficha_columnas'] ?? '{}', true);
                        $writer->addRow(ArchivoProductos::filaDeTexto([
                            ...array_map(fn (string $columna): string => (string) $fila[$columna], ArchivoProductos::COLUMNAS),
                            ...array_map(fn (string $campo): string => (string) ($ficha[$campo] ?? ''), $adicionales),
                        ]));
                    }
                }
                $writer->addNewSheetAndMakeItCurrent()->setName('Errores por fila original');
                $writer->addRow(Row::fromValues(['Fila del archivo original', 'Qué corregir']));
                foreach ($importacion->revision['errores'] as $fila => $mensajes) {
                    $writer->addRow(ArchivoProductos::filaDeTexto([$fila, implode(' ', $mensajes)]));
                }
                $writer->close();
                readfile($ruta);
            } finally {
                unlink($ruta);
            }
        }, 'productos-por-corregir.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
