<?php

namespace App\Enums;

enum EstadoAjusteInventario: string
{
    case EnCurso = 'en_curso';
    case Cerrado = 'cerrado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnCurso => 'En curso',
            self::Cerrado => 'Cerrado',
        };
    }
}
