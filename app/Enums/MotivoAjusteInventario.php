<?php

namespace App\Enums;

enum MotivoAjusteInventario: string
{
    case Merma = 'merma';
    case Rotura = 'rotura';
    case Hallazgo = 'hallazgo';
    case UsoInterno = 'uso_interno';
    case DiferenciaConteo = 'diferencia_conteo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Merma => 'Merma',
            self::Rotura => 'Rotura',
            self::Hallazgo => 'Hallazgo',
            self::UsoInterno => 'Uso interno',
            self::DiferenciaConteo => 'Diferencia de conteo',
        };
    }

    public function suma(): bool
    {
        return $this === self::Hallazgo;
    }
}
