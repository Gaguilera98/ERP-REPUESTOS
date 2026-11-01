<?php

namespace App\Enums;

enum MagnitudUnidad: string
{
    case Longitud = 'longitud';
    case Electrico = 'electrico';
    case Presion = 'presion';
    case Conteo = 'conteo';
    case Masa = 'masa';
    case Volumen = 'volumen';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Longitud => 'Longitud',
            self::Electrico => 'Eléctrico',
            self::Presion => 'Presión',
            self::Conteo => 'Conteo',
            self::Masa => 'Masa',
            self::Volumen => 'Volumen',
        };
    }
}
