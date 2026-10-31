<?php

namespace App\Enums;

enum ModoMoneda: string
{
    case SoloMoneda = 'solo_moneda';
    case ConDolar = 'con_dolar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SoloMoneda => 'Solo la moneda de la tienda',
            self::ConDolar => 'Con dólar de referencia',
        };
    }
}
