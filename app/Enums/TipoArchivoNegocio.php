<?php

namespace App\Enums;

enum TipoArchivoNegocio: string
{
    case GaleriaProducto = 'productos/galeria';
    case FotoVariante = 'productos/variantes';
    case ComprobantePago = 'pagos';
    case ComprobanteCajaChica = 'caja-chica';

    public function esPublico(): bool
    {
        return match ($this) {
            self::GaleriaProducto, self::FotoVariante => true,
            self::ComprobantePago, self::ComprobanteCajaChica => false,
        };
    }
}
