<?php

namespace App\Enums;

enum TipoCajaChicaArqueo: string
{
    case Apertura = 'apertura';
    case Entrega = 'entrega';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Apertura => 'Apertura',
            self::Entrega => 'Entrega de responsable',
        };
    }
}
