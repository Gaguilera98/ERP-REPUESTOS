<?php

namespace App\Enums;

enum EstadoPago: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Expirado = 'expirado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aprobado => 'Aprobado',
            self::Expirado => 'Expirado',
        };
    }
}
