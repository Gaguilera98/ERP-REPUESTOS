<?php

namespace App\Exceptions\Tenancy;

use RuntimeException;

class TenantContextMismatchException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El registro pertenece a otro tenant.');
    }
}
