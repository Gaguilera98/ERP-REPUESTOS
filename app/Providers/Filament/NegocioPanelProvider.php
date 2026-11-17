<?php

namespace App\Providers\Filament;

use App\Filament\Negocio\Auth\Login;
use App\Filament\Negocio\Pages\ElegirSucursal;
use App\Filament\Negocio\Pages\MostradorDelNegocio;
use App\Filament\Negocio\Resources\Sucursals\SucursalResource;
use App\Filament\Paleta;
use App\Http\Controllers\CambiarSucursalActiva;
use App\Http\Middleware\RequerirConfiguracionInicial;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament as FilamentFacade;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class NegocioPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('negocio')
            ->path('negocio')
            ->login(Login::class)
            ->authGuard('tenant')
            ->globalSearch(false)
            ->authenticatedRoutes(function (): void {
                Route::post('/sucursal-activa/{sucursal}', CambiarSucursalActiva::class)
                    ->whereNumber('sucursal')
                    ->name('sucursal-activa');
            })
            ->darkMode()
            ->colors(Paleta::colors())
            ->viteTheme('resources/css/filament/negocio/theme.css')
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => request()->routeIs('filament.negocio.auth.login')
                    ? ''
                    : view('filament.partials.splash-screen')->render(),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.paleta')->render(),
            )
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, function (): string {
                $usuario = auth('tenant')->user();
                $acceso = app(AccesoSucursales::class);
                if (! $usuario instanceof TenantUser) {
                    return '';
                }

                $sucursalId = $acceso->activa($usuario);
                $enMostrador = request()->routeIs(MostradorDelNegocio::getRouteName());
                $sucursales = $acceso->disponibles($usuario)
                    ->orderBy('nombre')
                    ->with(['asignaciones' => fn (HasMany $asignaciones): HasMany => $asignaciones
                        ->where('es_principal', true)->with('almacen')])
                    ->get();
                $puedeCambiar = ! $enMostrador && $sucursales->count() > 1;

                return view('filament.negocio.pages.sucursal-activa', [
                    'sucursalId' => $sucursalId,
                    'nombre' => $sucursales->firstWhere('id', $sucursalId)?->nombre,
                    'sucursales' => $sucursales,
                    'puedeCambiar' => $puedeCambiar,
                    'urlElegir' => $sucursales->isEmpty() && ! $enMostrador ? ElegirSucursal::getUrl() : null,
                    'urlAdministrar' => $puedeCambiar && $usuario->hasRole(AccesoSucursales::ADMINISTRADOR) && SucursalResource::canAccess()
                        ? SucursalResource::getUrl()
                        : null,
                ])->render();
            })
            ->discoverResources(in: app_path('Filament/Negocio/Resources'), for: 'App\Filament\Negocio\Resources')
            ->discoverPages(in: app_path('Filament/Negocio/Pages'), for: 'App\Filament\Negocio\Pages')
            ->discoverWidgets(in: app_path('Filament/Negocio/Widgets'), for: 'App\Filament\Negocio\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                InitializeTenancyBySubdomain::class,
                PreventAccessFromCentralDomains::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authMiddleware([
                RequerirConfiguracionInicial::class,
            ], isPersistent: true)
            ->plugins([
                FilamentShieldPlugin::make()
                    ->centralApp(fn (): bool => FilamentFacade::getCurrentPanel()?->getId() === 'admin'),
            ]);
    }
}
