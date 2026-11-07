<?php

namespace App\Models;

use App\Enums\MetodoPago;
use App\Enums\ModoMoneda;
use App\Enums\Moneda;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\NegocioConfiguracionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'horas_reserva',
    'permite_reembolso_senas',
    'descuento_max_porcentaje',
    'descuento_max_importe',
    'moneda',
    'modo_moneda',
    'siguiente_numero_venta',
    'comprobante_obligatorio_qr_externo',
    'comprobante_obligatorio_transferencia',
    'comprobante_obligatorio_voucher_tarjeta',
])]
class NegocioConfiguracion extends Model
{
    /** @use HasFactory<NegocioConfiguracionFactory> */
    use BelongsToTenantPool, HasFactory;

    public const COMPROBANTE_EXTENSIONES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const COMPROBANTE_MAX_KB = 5120;

    protected $table = 'negocio_configuracion';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'horas_reserva' => 'integer',
            'permite_reembolso_senas' => 'boolean',
            'descuento_max_porcentaje' => 'decimal:2',
            'descuento_max_importe' => 'decimal:2',
            'moneda' => Moneda::class,
            'modo_moneda' => ModoMoneda::class,
            'siguiente_numero_venta' => 'integer',
            'comprobante_obligatorio_qr_externo' => 'boolean',
            'comprobante_obligatorio_transferencia' => 'boolean',
            'comprobante_obligatorio_voucher_tarjeta' => 'boolean',
        ];
    }

    public static function codigoMoneda(): string
    {
        return static::query()->first()?->moneda->value ?? Moneda::Bob->value;
    }

    public function exigeComprobanteArchivo(MetodoPago $metodo): bool
    {
        return match ($metodo) {
            MetodoPago::QrExterno => $this->comprobante_obligatorio_qr_externo,
            MetodoPago::Transferencia => $this->comprobante_obligatorio_transferencia,
            MetodoPago::VoucherTarjeta => $this->comprobante_obligatorio_voucher_tarjeta,
            MetodoPago::Efectivo => false,
        };
    }

    /**
     * @return array<string, bool>
     */
    public function comprobantesObligatorios(): array
    {
        return [
            MetodoPago::QrExterno->value => $this->comprobante_obligatorio_qr_externo,
            MetodoPago::Transferencia->value => $this->comprobante_obligatorio_transferencia,
            MetodoPago::VoucherTarjeta->value => $this->comprobante_obligatorio_voucher_tarjeta,
        ];
    }
}
