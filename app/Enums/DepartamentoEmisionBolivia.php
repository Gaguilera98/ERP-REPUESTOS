<?php

namespace App\Enums;

enum DepartamentoEmisionBolivia: string
{
    case LaPaz = 'LP';
    case SantaCruz = 'SCZ';
    case Cochabamba = 'CB';
    case Oruro = 'OR';
    case Potosi = 'PT';
    case Tarija = 'TJ';
    case Chuquisaca = 'CH';
    case Beni = 'BN';
    case Pando = 'PD';

    public function etiqueta(): string
    {
        return match ($this) {
            self::LaPaz => 'La Paz (LP)',
            self::SantaCruz => 'Santa Cruz (SCZ)',
            self::Cochabamba => 'Cochabamba (CB)',
            self::Oruro => 'Oruro (OR)',
            self::Potosi => 'Potosí (PT)',
            self::Tarija => 'Tarija (TJ)',
            self::Chuquisaca => 'Chuquisaca (CH)',
            self::Beni => 'Beni (BN)',
            self::Pando => 'Pando (PD)',
        };
    }
}
