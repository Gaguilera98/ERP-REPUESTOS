<?php

namespace App\Enums;

enum EstadoOrdenCompra: string
{
    case Pendiente = 'pendiente';
    case RecibidaParcial = 'recibida_parcial';
    case Completada = 'completada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::RecibidaParcial => 'Recibida parcial',
            self::Completada => 'Completada',
            self::Anulada => 'Anulada',
        };
    }

    public function puedeRecibir(): bool
    {
        return $this === self::Pendiente || $this === self::RecibidaParcial;
    }
}
