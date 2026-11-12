<?php

namespace App\Enums;

enum DevolucionComercialProducto: string
{
    case Hereda = 'hereda';
    case Permite = 'permite';
    case Bloquea = 'bloquea';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Hereda => 'Hereda la categoría',
            self::Permite => 'Sí se devuelve',
            self::Bloquea => 'No se devuelve',
        };
    }
}
