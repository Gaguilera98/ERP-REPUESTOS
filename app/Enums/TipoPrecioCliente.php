<?php

namespace App\Enums;

enum TipoPrecioCliente: string
{
    case Menor = 'menor';
    case Mayor = 'mayor';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Menor => 'Menor',
            self::Mayor => 'Mayor',
        };
    }
}
