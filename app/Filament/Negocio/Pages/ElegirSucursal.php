<?php

namespace App\Filament\Negocio\Pages;

use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

class ElegirSucursal extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = '¿En qué sucursal vas a trabajar?';

    protected string $view = 'filament.negocio.pages.elegir-sucursal';

    #[Url]
    public ?string $volver = null;

    public static function canAccess(): bool
    {
        return tenancy()->initialized && auth('tenant')->user() instanceof TenantUser;
    }

    public function elegir(int $id, AccesoSucursales $acceso): void
    {
        abort_unless(static::canAccess(), 403);

        $acceso->seleccionar(auth('tenant')->user(), $id);
        $this->redirect($this->volver === 'mostrador' && MostradorDelNegocio::canAccess()
            ? MostradorDelNegocio::getUrl() : Dashboard::getUrl());
    }

    protected function getViewData(): array
    {
        return ['sucursales' => app(AccesoSucursales::class)->disponibles(auth('tenant')->user())->orderBy('nombre')->get(['id', 'nombre'])];
    }
}
