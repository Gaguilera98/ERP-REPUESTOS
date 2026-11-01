<?php

namespace App\Enums;

enum TipoComprobante: string
{
    case NotaVenta = 'nota_venta';
    case FacturaManual = 'factura_manual';

    public function etiqueta(): string
    {
        return match ($this) {
            self::NotaVenta => 'Nota de venta',
            self::FacturaManual => 'Factura manual',
        };
    }
}
