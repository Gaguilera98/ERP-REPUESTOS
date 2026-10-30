<?php

namespace App\Http\Middleware;

use App\Tenancy\PlatformTeam;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPlatformPermissionTeam
{
    /**
     * El id queda puesto para el resto del request. Livewire ejecuta este
     * middleware en un pipeline que termina antes de la acción del formulario,
     * y si se restaura aquí la política de crear negocio responde 403.
     */
    public function handle(Request $request, Closure $next): Response
    {
        setPermissionsTeamId(PlatformTeam::Id);

        return $next($request);
    }
}
