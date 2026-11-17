<?php

namespace App\Filament\Negocio\Pages;

use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConfiguracionInicial extends Page
{
    protected static ?string $title = 'Configuración inicial';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected Width|string|null $maxContentWidth = Width::ThreeExtraLarge;

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
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sucursal')
                    ->description('El local desde el que vas a vender.')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        Select::make('sucursal')->label('Sucursal')->live()->native(false)->required()
                            ->options(fn (): array => ['nueva' => 'Crear sucursal'] + $this->sucursalesDisponibles()),
                        TextInput::make('nombre_sucursal')->label('Nombre de la sucursal')->placeholder('Ej. Local Centro')
                            ->required()->trim()->maxLength(255)->scopedUnique(Sucursal::class, 'nombre')
                            ->validationMessages(['unique' => 'Ya existe una sucursal con este nombre.'])
                            ->visible(fn (Get $get): bool => $get('sucursal') === 'nueva'),
                    ]),
                Section::make('Almacén principal')
                    ->description('El lugar donde vas a guardar el stock de esta sucursal.')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->schema([
                        Select::make('almacen')->label('Almacén')->live()->native(false)->required()
                            ->options(fn (): array => ['nuevo' => 'Crear almacén'] + $this->almacenesDisponibles()),
                        TextInput::make('nombre_almacen')->label('Nombre del almacén')->required()->trim()->maxLength(255)
                            ->scopedUnique(Almacen::class, 'nombre')
                            ->validationMessages(['unique' => 'Ya existe un almacén con este nombre.'])
                            ->visible(fn (Get $get): bool => $get('almacen') === 'nuevo'),
                    ]),
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

            if ($datos['sucursal'] === 'nueva') {
                $sucursal = Sucursal::query()->create(['nombre' => $datos['nombre_sucursal'], 'is_active' => true]);
            } else {
                $sucursal = Sucursal::query()->where('is_active', true)->findOrFail($datos['sucursal']);
                $gate->authorize('update', $sucursal);
            }

            if ($datos['almacen'] === 'nuevo') {
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
}
