<?php

namespace App\Enums;

enum CondicionVenta: string
{
    case Contado = 'contado';
    case Credito = 'credito';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contado => 'Contado',
            self::Credito => 'Crédito',
        };
    }
}
