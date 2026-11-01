<?php

namespace App\Enums;

enum EstadoCajaSesion: string
{
    case Abierta = 'abierta';
    case Cerrada = 'cerrada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Abierta => 'Abierta',
            self::Cerrada => 'Cerrada',
        };
    }
}
