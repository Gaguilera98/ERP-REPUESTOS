<?php

namespace App\Filament\Negocio\Pages;

use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class ConfiguracionInicial extends Page
{
    protected static ?string $title = 'Configuración inicial';

    protected ?string $subheading = 'Vinculá tu primera sucursal con su almacén principal para habilitar los módulos del negocio.';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    protected string $view = 'filament.negocio.pages.configuracion-inicial';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return tenancy()->initialized && Filament::auth()->user() instanceof TenantUser;
    }

    public static function estaPendiente(): bool
    {
        return Sucursal::query()->where('is_active', true)
            ->whereHas('asignaciones', fn (Builder $query): Builder => $query->where('es_principal', true))
            ->doesntExist();
    }

    public static function puedeConfigurar(): bool
    {
        $usuario = Filament::auth()->user();

        return $usuario instanceof TenantUser
            && $usuario->can('create', Sucursal::class)
            && $usuario->can('create', Almacen::class);
    }

    #[Unexposed]
    public function hasLogo(): bool
    {
        return true;
    }

    protected function getLayoutData(): array
    {
        return ['hasTopbar' => false];
    }

    public function mount(): void
    {
        if (! static::estaPendiente()) {
            $this->redirect(Dashboard::getUrl());

            return;
        }

        if (! static::puedeConfigurar()) {
            return;
        }

        $sucursales = $this->sucursalesDisponibles();
        $almacenes = $this->almacenesDisponibles();

        $this->form->fill([
            'sucursal' => count($sucursales) === 1 ? array_key_first($sucursales) : 'nueva',
            'almacen' => count($almacenes) === 1 ? array_key_first($almacenes) : 'nuevo',
            'nombre_almacen' => 'Almacén principal',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $sucursales = $this->sucursalesDisponibles();
        $almacenes = $this->almacenesDisponibles();

        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Sucursal')->icon(Heroicon::OutlinedBuildingStorefront)
                        ->description('Dónde vas a vender')
                        ->schema([
                            Section::make('Definí tu sucursal')->schema([
                                Select::make('sucursal')->label('Sucursal')->live()->native(false)->required()
                                    ->options(['nueva' => 'Crear sucursal'] + $sucursales)
                                    ->visible($sucursales !== []),
                                TextInput::make('nombre_sucursal')->label('Nombre de la sucursal')->placeholder('Ej. Local Centro')
                                    ->helperText('Así la van a ver tus vendedores al abrir caja.')
                                    ->required()->trim()->maxLength(255)->scopedUnique(Sucursal::class, 'nombre')
                                    ->validationMessages(['unique' => 'Ya existe una sucursal con este nombre.'])
                                    ->visible(fn (Get $get): bool => $sucursales === [] || $get('sucursal') === 'nueva'),
                            ]),
                        ]),
                    Step::make('Almacén principal')->icon(Heroicon::OutlinedArchiveBox)
                        ->description('Dónde guardás el stock')
                        ->schema([
                            Section::make('Definí tu almacén principal')->schema([
                                Select::make('almacen')->label('Almacén')->live()->native(false)->required()
                                    ->options(['nuevo' => 'Crear almacén'] + $almacenes)
                                    ->visible($almacenes !== []),
                                TextInput::make('nombre_almacen')->label('Nombre del almacén')->required()->trim()->maxLength(255)
                                    ->scopedUnique(Almacen::class, 'nombre')
                                    ->validationMessages(['unique' => 'Ya existe un almacén con este nombre.'])
                                    ->visible(fn (Get $get): bool => $almacenes === [] || $get('almacen') === 'nuevo'),
                            ]),
                        ]),
                    Step::make('Revisión')->icon(Heroicon::OutlinedCheckCircle)
                        ->description('Confirmá antes de entrar')
                        ->schema([
                            Section::make('Revisá tu configuración')
                                ->description('El almacén quedará vinculado como principal de esta sucursal.')
                                ->schema([
                                    TextEntry::make('resumen_sucursal')->label('Sucursal')
                                        ->state(fn (Get $get): ?string => $this->nombreParaRevision($sucursales, $get('sucursal'), $get('nombre_sucursal'))),
                                    TextEntry::make('resumen_almacen')->label('Almacén principal')
                                        ->state(fn (Get $get): ?string => $this->nombreParaRevision($almacenes, $get('almacen'), $get('nombre_almacen'))),
                                ])->columns(2),
                        ]),
                ])
                    ->key('configuracionInicial')
                    ->contained(false)
                    ->extraAttributes(['class' => 'configuracion-inicial-wizard'])
                    ->nextAction(fn (Action $action): Action => $action->label('Continuar'))
                    ->previousAction(fn (Action $action): Action => $action->label('Volver'))
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit" icon="heroicon-m-check">Guardar y entrar</x-filament::button>',
                    ))),
            ]);
    }

    public function guardar(): void
    {
        abort_unless(static::puedeConfigurar(), 403);
        $gate = Gate::forUser(Filament::auth()->user());

        $guardado = DB::transaction(function () use ($gate): bool {
            NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();

            if (! static::estaPendiente()) {
                return false;
            }

            $datos = $this->form->getState();

            if (($datos['sucursal'] ?? 'nueva') === 'nueva') {
                $sucursal = Sucursal::query()->create(['nombre' => $datos['nombre_sucursal'], 'is_active' => true]);
            } else {
                $sucursal = Sucursal::query()->where('is_active', true)->findOrFail($datos['sucursal']);
                $gate->authorize('update', $sucursal);
            }

            if (($datos['almacen'] ?? 'nuevo') === 'nuevo') {
                $almacen = Almacen::query()->create(['nombre' => $datos['nombre_almacen']]);
            } else {
                $almacen = Almacen::query()->findOrFail($datos['almacen']);
                $gate->authorize('view', $almacen);
            }

            $sucursal->asignaciones()->updateOrCreate(['almacen_id' => $almacen->getKey()], ['es_principal' => true]);

            return true;
        });

        if ($guardado) {
            Notification::make()->success()->title('Sucursal y almacén configurados')->send();
        }

        $this->redirect(Dashboard::getUrl());
    }

    /** @return array<int, string> */
    private function sucursalesDisponibles(): array
    {
        if (! Filament::auth()->user()?->can('Update:Sucursal')) {
            return [];
        }

        return Sucursal::query()->where('is_active', true)->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /** @return array<int, string> */
    private function almacenesDisponibles(): array
    {
        if (! Filament::auth()->user()?->can('View:Almacen')) {
            return [];
        }

        return Almacen::query()->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /** @param array<int, string> $opciones */
    private function nombreParaRevision(array $opciones, mixed $seleccion, mixed $nombre): ?string
    {
        $nombreNuevo = is_string($nombre) ? trim($nombre) : null;

        return is_int($seleccion) || is_string($seleccion)
            ? ($opciones[$seleccion] ?? $nombreNuevo)
            : $nombreNuevo;
    }
}
