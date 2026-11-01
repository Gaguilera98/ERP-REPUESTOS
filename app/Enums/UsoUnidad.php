<?php

namespace App\Enums;

enum UsoUnidad: string
{
    case Atributo = 'atributo';
    case Cantidad = 'cantidad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Atributo => 'Medida de un dato del rubro',
            self::Cantidad => 'Unidad para comprar, stock y vender',
        };
    }
}
