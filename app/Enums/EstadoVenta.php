<?php

namespace App\Enums;

enum EstadoVenta: string
{
    case Pendiente = 'pendiente';
    case Completada = 'completada';
    case Anulada = 'anulada';
    case Reserva = 'reserva';
    case Vencida = 'vencida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Completada => 'Completada',
            self::Anulada => 'Anulada',
            self::Reserva => 'Reserva',
            self::Vencida => 'Vencida',
        };
    }
}
