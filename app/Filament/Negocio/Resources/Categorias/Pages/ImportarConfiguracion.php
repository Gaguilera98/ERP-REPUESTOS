<?php

namespace App\Filament\Negocio\Resources\Categorias\Pages;

use App\Catalogo\ArchivoConfiguracionCatalogo;
use App\Catalogo\ImportarConfiguracionCatalogo;
use App\Filament\Negocio\Resources\Categorias\CategoriaResource;
use App\Models\Categoria;
use App\Models\UnidadMedida;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportarConfiguracion extends Page
{
    protected static string $resource = CategoriaResource::class;

    protected string $view = 'filament.negocio.resources.categorias.pages.importar-configuracion';

    public ?array $data = [];

    public ?array $asignacionesData = [];

    #[Locked]
    public ?array $archivo = null;

    #[Locked]
    public ?array $revision = null;

    #[Locked]
    public string $tenantOrigen = '';

    #[Locked]
    public string $nombreArchivo = '';

    public static function canAccess(array $parameters = []): bool
    {
        $usuario = auth('tenant')->user();

        return $usuario?->can('viewAny', Categoria::class) && $usuario?->can('create', Categoria::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->tenantOrigen = (string) tenant()->getTenantKey();
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Importar categorías y unidades';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('plantilla')->label('Descargar plantilla')->color('gray')->icon('heroicon-o-arrow-down-tray')->action(fn (): StreamedResponse => $this->descargarPlantilla())];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Subir configuración')->description('Descargá la plantilla y abrí Empezar aqui. Podés cargar solo los nombres de tus categorías o agregar sus campos y unidades. Incluye un ejemplo de Rodamientos. Subir el archivo no crea registros.')
                ->schema([FileUpload::make('archivo')->label('Archivo Excel')->required()->storeFiles(false)->maxSize(5120)
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->validationMessages(['required' => 'Seleccioná el Excel de configuración.', 'mimetypes' => 'Subí un Excel con extensión .xlsx.', 'max' => 'El archivo admite hasta 5 MB.', 'uploaded' => 'No se pudo subir el archivo. Volvé a seleccionarlo.'])]),
        ]);
    }

    public function asignacionesForm(Schema $schema): Schema
    {
        $opciones = $this->archivo === null ? [] : UnidadMedida::query()->orderBy('nombre')->get()->mapWithKeys(fn (UnidadMedida $unidad): array => [$unidad->id => $unidad->nombre.' ('.$unidad->simbolo.') · '.$unidad->uso->value.' · '.$unidad->magnitud->etiqueta()])->all();

        return $schema->statePath('asignacionesData')->components([
            Section::make('Relacionar unidades')->description('Elegí una existente solo si representa la misma medida. Se comprobarán uso, magnitud y, para stock, factor y decimales. No se modifican unidades existentes.')
                ->schema(collect($this->archivo['Unidades'] ?? [])->map(fn (array $fila): Select => Select::make('unidades.'.strtoupper($fila['codigo']))
                    ->label($fila['codigo'].' · '.$fila['nombre'].' ('.$fila['simbolo'].')')->searchable()->native(false)
                    ->placeholder('Resolver por nombre y símbolo del Excel')
                    ->options($opciones))->all())
                ->columns(2),
        ]);
    }

    private function autorizar(): void
    {
        abort_unless(static::canAccess() && $this->tenantOrigen === (string) tenant()->getTenantKey(), 403);
    }

    public function revisar(): void
    {
        $this->autorizar();
        $estado = $this->form->getState();
        $archivo = $estado['archivo'];
        $contenido = app(ArchivoConfiguracionCatalogo::class)->leer($archivo->getRealPath());
        $revision = app(ImportarConfiguracionCatalogo::class)->revisar($contenido, [], auth('tenant')->user());
        $this->archivo = $contenido;
        $this->revision = $revision;
        $this->nombreArchivo = $archivo->getClientOriginalName();
        $this->asignacionesForm->fill(['unidades' => collect($revision['unidades'])->mapWithKeys(fn (array $fila, string $codigo): array => [$codigo => $fila['id']])->all()]);
        $this->resetValidation();
    }

    public function actualizarRevision(): void
    {
        $this->autorizar();
        abort_unless($this->archivo !== null, 422);
        $estado = $this->asignacionesForm->getState();
        $this->revision = app(ImportarConfiguracionCatalogo::class)->revisar($this->archivo, $estado['unidades'] ?? [], auth('tenant')->user());
        $this->resetValidation();
    }

    public function confirmarAction(): Action
    {
        return Action::make('confirmar')->label('Confirmar configuración')->icon('heroicon-o-check')->requiresConfirmation()
            ->modalHeading('Crear la configuración revisada')->modalDescription('Se crearán las unidades, categorías y atributos indicados. Los registros reutilizados se conservan. La operación se aplicará completa o no se aplicará.')
            ->action(fn () => $this->confirmar());
    }

    public function confirmar(): void
    {
        $this->autorizar();
        abort_unless($this->archivo !== null && $this->revision !== null, 422);
        $estado = $this->asignacionesForm->getState();
        $resultado = app(ImportarConfiguracionCatalogo::class)->confirmar($this->archivo, $estado['unidades'] ?? [], $this->revision['version'], auth('tenant')->user());
        $this->cambiarArchivo();
        Notification::make()->title('Configuración importada')->body($resultado['unidades'].' unidades, '.$resultado['categorias'].' categorías y '.$resultado['atributos'].' atributos creados.')->success()->send();
    }

    public function cambiarArchivo(): void
    {
        $this->autorizar();
        $this->archivo = null;
        $this->revision = null;
        $this->nombreArchivo = '';
        $this->form->fill();
        $this->resetValidation();
    }

    public function descargarPlantilla(): StreamedResponse
    {
        $this->autorizar();
        $ruta = tempnam(sys_get_temp_dir(), 'configuracion-catalogo-');
        try {
            app(ArchivoConfiguracionCatalogo::class)->plantilla($ruta);
        } catch (Throwable $exception) {
            unlink($ruta);
            throw $exception;
        }

        return response()->streamDownload(function () use ($ruta): void {
            try {
                readfile($ruta);
            } finally {
                unlink($ruta);
            }
        }, 'plantilla-categorias-unidades.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
