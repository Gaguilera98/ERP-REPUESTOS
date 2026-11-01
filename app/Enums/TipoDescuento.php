<?php

namespace App\Enums;

enum TipoDescuento: string
{
    case Porcentaje = 'porcentaje';
    case Importe = 'importe';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje',
            self::Importe => 'Importe',
        };
    }
}
