<?php

namespace App\Enums;

enum Moneda: string
{
    case Bob = 'BOB';
    case Pen = 'PEN';
    case Brl = 'BRL';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bob => 'Boliviano (Bs)',
            self::Pen => 'Sol (PEN)',
            self::Brl => 'Real (BRL)',
        };
    }
}
