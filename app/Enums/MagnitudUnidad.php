<?php

namespace App\Enums;

enum MagnitudUnidad: string
{
    case Longitud = 'longitud';
    case Electrico = 'electrico';
    case Presion = 'presion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Longitud => 'Longitud',
            self::Electrico => 'Eléctrico',
            self::Presion => 'Presión',
        };
    }
}
