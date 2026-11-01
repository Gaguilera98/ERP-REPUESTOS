<?php

namespace App\Exceptions\Compras;

use RuntimeException;

class ProveedorOcasionalProtegidoException extends RuntimeException
{
    public function __construct(string $message = 'El proveedor ocasional del negocio no se borra.')
    {
        parent::__construct($message);
    }
}
