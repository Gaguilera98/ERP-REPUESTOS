<?php

namespace App\Enums;

enum TipoComprobanteCajaChica: string
{
    case ReciboInterno = 'recibo_interno';
    case Factura = 'factura';
    case SinComprobante = 'sin_comprobante';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ReciboInterno => 'Recibo interno',
            self::Factura => 'Factura',
            self::SinComprobante => 'Sin comprobante',
        };
    }
}
