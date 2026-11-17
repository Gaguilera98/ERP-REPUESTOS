<?php

namespace App\Filament\Negocio\Resources\Ventas\Pages;

use App\Filament\Negocio\Resources\Ventas\VentaResource;
use App\Ventas\GestionarReservas;
use Filament\Resources\Pages\ListRecords;

class ListVentas extends ListRecords
{
    protected static string $resource = VentaResource::class;

    protected function authorizeAccess(): void
    {
        static::authorizeResourceAccess();

        app(GestionarReservas::class)->procesarVencidas();
    }
}
