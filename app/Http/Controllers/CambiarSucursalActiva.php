<?php

namespace App\Http\Controllers;

use App\Filament\Negocio\Pages\Dashboard;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CambiarSucursalActiva extends Controller
{
    public function __invoke(Request $request, int $sucursal, AccesoSucursales $acceso): RedirectResponse
    {
        $usuario = $request->user('tenant');
        abort_unless($usuario instanceof TenantUser, 403);

        $acceso->seleccionar($usuario, $sucursal);

        return to_route(Dashboard::getRouteName());
    }
}
