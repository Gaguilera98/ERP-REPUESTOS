<?php

namespace App\Filament\Negocio\Resources\Clientes\Pages;

use App\Filament\Negocio\Resources\Clientes\ClienteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCliente extends CreateRecord
{
    protected static string $resource = ClienteResource::class;
}
