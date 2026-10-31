<?php

namespace App\Filament\Negocio\Resources\ProductoMaestros\Pages;

use App\Filament\Negocio\Resources\ProductoMaestros\Concerns\GuardaVariantesDelProducto;
use App\Filament\Negocio\Resources\ProductoMaestros\ProductoMaestroResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductoMaestro extends CreateRecord
{
    use GuardaVariantesDelProducto;

    protected static string $resource = ProductoMaestroResource::class;
}
