<?php

namespace App\Filament\Negocio\Pages;

use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $usuario = Filament::auth()->user();
        if (! $usuario instanceof TenantUser) {
            return;
        }

        if (app(AccesoSucursales::class)->activa($usuario) === null) {
            $this->redirect(ElegirSucursal::getUrl());
        }
    }
}
