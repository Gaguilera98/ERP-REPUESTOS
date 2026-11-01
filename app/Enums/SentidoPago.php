<?php

namespace App\Enums;

enum SentidoPago: string
{
    case Cobro = 'cobro';
    case Reembolso = 'reembolso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cobro => 'Cobro',
            self::Reembolso => 'Reembolso',
        };
    }
}
