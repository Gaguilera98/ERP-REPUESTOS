<?php

namespace App\Exceptions\Compras;

use RuntimeException;

class KardexInmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El kardex solo se inserta.');
    }
}
