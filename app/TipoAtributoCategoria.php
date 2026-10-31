<?php

namespace App;

enum TipoAtributoCategoria: string
{
    case Decimal = 'decimal';
    case Texto = 'texto';
    case Lista = 'lista';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Decimal => 'Decimal',
            self::Texto => 'Texto',
            self::Lista => 'Lista',
        };
    }
}
