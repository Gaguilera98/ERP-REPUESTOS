<?php

namespace App\Enums;

enum TipoAjusteInventario: string
{
    case Puntual = 'puntual';
    case Conteo = 'conteo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Puntual => 'Ajuste',
            self::Conteo => 'Conteo',
        };
    }
}
