<?php

namespace App\Enums;

enum EstadoCajaSesion: string
{
    case Abierta = 'abierta';
    case PendienteArqueo = 'pendiente_arqueo';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierta => 'Abierta',
            self::PendienteArqueo => 'Pendiente de contar',
            self::Cerrada => 'Cerrada',
            self::Anulada => 'Anulada',
        };
    }
}
