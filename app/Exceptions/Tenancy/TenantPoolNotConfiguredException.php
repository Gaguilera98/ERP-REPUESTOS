<?php

namespace App\Exceptions\Tenancy;

use RuntimeException;

class TenantPoolNotConfiguredException extends RuntimeException
{
    public function __construct(string $pool)
    {
        $label = $pool === '' ? '(vacío)' : $pool;

        parent::__construct("El tenant no tiene un pool de base de datos utilizable [{$label}].");
    }
}
