<?php

namespace App\Http\Middleware;

use App\Filament\Negocio\Pages\ConfiguracionInicial;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequerirConfiguracionInicial
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getPanel('negocio');

        if (! $request->routeIs(ConfiguracionInicial::getRouteName($panel), 'filament.negocio.auth.logout')
            && ConfiguracionInicial::estaPendiente()) {
            return redirect()->to(ConfiguracionInicial::getUrl(panel: $panel->getId()));
        }

        return $next($request);
    }
}
