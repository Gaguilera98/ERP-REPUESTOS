<?php

namespace App\Enums;

enum TipoCliente: string
{
    case PersonaNatural = 'persona_natural';
    case Empresa = 'empresa';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PersonaNatural => 'Persona natural',
            self::Empresa => 'Empresa',
        };
    }
}
