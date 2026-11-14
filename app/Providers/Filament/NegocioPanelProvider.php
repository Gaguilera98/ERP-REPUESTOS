<?php

namespace App\Providers\Filament;

use App\Filament\Paleta;
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
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            ->login()
            ->authGuard('tenant')
            ->darkMode()
            ->colors(Paleta::colors())
            ->viteTheme('resources/css/filament/negocio/theme.css')
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.partials.splash-screen')->render(),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.paleta')->render(),
            )
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
            ->plugins([
                FilamentShieldPlugin::make()
                    ->centralApp(fn (): bool => FilamentFacade::getCurrentPanel()?->getId() === 'admin'),
            ]);
    }
}
