<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Catalogo\ArchivoProductos;
use App\Catalogo\ImportarProductos as Importador;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use App\Models\ImportacionProducto;
use App\Models\NegocioConfiguracion;
use App\Tenancy\AccesoSucursales;
use Filament\Actions\Action;
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

    #[Url]
    public bool $inventario = false;

    #[Url(as: 'importacion')]
    #[Locked]
    public ?int $importacionId = null;

    public int $paginaRevision = 1;

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
            Section::make('Subir Excel')->description('Usá nuestra plantilla. Los precios y costos se expresan en '.$this->moneda().'. Hasta '.ArchivoProductos::MAX_FILAS.' filas por archivo.')
                ->schema([
                    FileUpload::make('archivo')->label('Archivo de productos')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->maxSize(5120)->columnSpanFull(),
                    Toggle::make('actualizar_existentes')->label('Actualizar datos de los SKU existentes')
                        ->helperText('Apagado, los productos existentes conservan sus datos. Los SKU nuevos se crean.')
                        ->visible(fn (): bool => auth('tenant')->user()->can('Update:ProductoMaestro')),
                    Toggle::make('inventario')->label('Incluir inventario inicial')->live()
                        ->helperText('Para mercadería que ya tenés. Cada fila con cantidad y costo registra un lote en el almacén elegido.')
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
            ->action(fn (): StreamedResponse => $this->descargarPlantilla())];
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
            $filas = app(ArchivoProductos::class)->leer($local);
        } finally {
            unlink($local);
        }
        $conInventario = (bool) ($data['inventario'] ?? false);
        $importacion = app(Importador::class)->preparar(
            $filas, $archivo->getClientOriginalName(), $conInventario ? (int) $data['almacen_id'] : null,
            $conInventario ? $data['fecha_inventario'] : null, (bool) ($data['actualizar_existentes'] ?? false), auth('tenant')->user(),
        );
        $this->importacionId = $importacion->id;
        $this->paginaRevision = 1;
        unset($this->importacion);
    }

    #[Computed]
    public function importacion(): ?ImportacionProducto
    {
        return $this->importacionId === null ? null : $this->getImportacion();
    }

    private function getImportacion(): ImportacionProducto
    {
        abort_unless(static::canAccess(), 403);
        $importacion = ImportacionProducto::query()->where('user_id', auth('tenant')->id())->with(['almacen', 'usuario'])->findOrFail($this->importacionId);
        if ($importacion->almacen_id) {
            abort_unless(auth('tenant')->user()->can('Cargar:InventarioInicial') && auth('tenant')->user()->can('ViewAny:InventarioStock')
                && app(AccesoSucursales::class)->autorizaAlmacen(auth('tenant')->user(), $importacion->almacen_id), 403);
        }

        return $importacion;
    }

    public function confirmar(): void
    {
        $importacion = $this->getImportacion();
        app(Importador::class)->confirmar($importacion->id, auth('tenant')->user());
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
        $this->importacionId = null;
        $this->paginaRevision = 1;
        unset($this->importacion);
        $this->form->fill(['inventario' => $this->inventario, 'fecha_inventario' => today()->format('Y-m-d'), 'actualizar_existentes' => false]);
        $this->resetValidation();
    }

    public function verImportacion(int $id): void
    {
        $this->importacionId = $id;
        $this->paginaRevision = 1;
        unset($this->importacion);
        $this->getImportacion();
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
            ])->recordActions([Action::make('ver')->label('Revisar')->icon('heroicon-o-eye')->action(fn (ImportacionProducto $record) => $this->verImportacion($record->id))])
            ->paginated([10, 25])->emptyStateHeading('Todavía no hay importaciones');
    }

    public function descargarPlantilla(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $moneda = $this->moneda();

        return response()->streamDownload(function () use ($moneda): void {
            $ruta = tempnam(sys_get_temp_dir(), 'plantilla-');
            try {
                app(ArchivoProductos::class)->plantilla($ruta, $moneda);
                readfile($ruta);
            } finally {
                unlink($ruta);
            }
        }, 'plantilla-productos.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function descargarErrores(): StreamedResponse
    {
        $importacion = $this->getImportacion();

        return response()->streamDownload(function () use ($importacion): void {
            $ruta = tempnam(sys_get_temp_dir(), 'errores-');
            $writer = new Writer;
            try {
                $writer->openToFile($ruta);
                $writer->getCurrentSheet()->setName('Productos');
                $writer->addRow(Row::fromValues(ArchivoProductos::COLUMNAS));
                foreach ($importacion->filas as $fila) {
                    if (isset($importacion->revision['errores'][$fila['fila']])) {
                        $writer->addRow(ArchivoProductos::filaDeTexto(array_map(fn (string $columna): string => (string) $fila[$columna], ArchivoProductos::COLUMNAS)));
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
