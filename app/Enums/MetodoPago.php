<?php

namespace App\Enums;

enum MetodoPago: string
{
    case Efectivo = 'efectivo';
    case QrExterno = 'qr_externo';
    case VoucherTarjeta = 'voucher_tarjeta';
    case Transferencia = 'transferencia';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::QrExterno => 'QR externo',
            self::VoucherTarjeta => 'Voucher de tarjeta',
            self::Transferencia => 'Transferencia',
        };
    }

    public function exigeCajaSesion(): bool
    {
        return $this === self::Efectivo;
    }
}
