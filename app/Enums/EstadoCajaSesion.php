<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;

enum EstadoCajaSesion: string implements HasColor
{
    case Abierta = 'abierta';
    case PendienteArqueo = 'pendiente_arqueo';
    case Cerrada = 'cerrada';
    case Anulada = 'anulada';

    /**
     * @return array<int, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::Abierta => Color::Emerald,
            self::PendienteArqueo => Color::Amber,
            self::Cerrada => Color::Blue,
            self::Anulada => Color::Red,
        };
    }

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
