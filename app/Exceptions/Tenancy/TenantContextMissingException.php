<?php

namespace App\Exceptions\Tenancy;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La consulta de datos del tenant requiere un tenant activo.');
    }
}
