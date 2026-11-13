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
    'acepta_devoluciones',
    'dias_devolucion',
    'devolucion_permite_cambio',
    'devolucion_permite_saldo_favor',
    'devolucion_permite_reembolso',
    'devolucion_permite_excepcion',
    'permite_reembolso_senas',
    'descuento_max_porcentaje',
    'descuento_max_importe',
    'moneda',
    'modo_moneda',
    'siguiente_numero_venta',
    'comprobante_obligatorio_qr_externo',
    'comprobante_obligatorio_transferencia',
    'comprobante_obligatorio_voucher_tarjeta',
    'permite_gastos_cajon_venta',
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
            'acepta_devoluciones' => 'boolean',
            'dias_devolucion' => 'integer',
            'devolucion_permite_cambio' => 'boolean',
            'devolucion_permite_saldo_favor' => 'boolean',
            'devolucion_permite_reembolso' => 'boolean',
            'devolucion_permite_excepcion' => 'boolean',
            'permite_reembolso_senas' => 'boolean',
            'descuento_max_porcentaje' => 'decimal:2',
            'descuento_max_importe' => 'decimal:2',
            'moneda' => Moneda::class,
            'modo_moneda' => ModoMoneda::class,
            'siguiente_numero_venta' => 'integer',
            'comprobante_obligatorio_qr_externo' => 'boolean',
            'comprobante_obligatorio_transferencia' => 'boolean',
            'comprobante_obligatorio_voucher_tarjeta' => 'boolean',
            'permite_gastos_cajon_venta' => 'boolean',
        ];
    }

    public static function codigoMoneda(): string
    {
        return static::query()->first()?->moneda->value ?? Moneda::Bob->value;
    }

    /**
     * @return array{version: int, acepta_devoluciones: bool, dias_devolucion: int, permite_cambio: bool, permite_saldo_favor: bool, permite_reembolso: bool, permite_excepcion: bool, zona_horaria: string}
     */
    public function politicaDevolucion(): array
    {
        return [
            'version' => 1,
            'acepta_devoluciones' => $this->acepta_devoluciones,
            'dias_devolucion' => $this->dias_devolucion,
            'permite_cambio' => $this->devolucion_permite_cambio,
            'permite_saldo_favor' => $this->devolucion_permite_saldo_favor,
            'permite_reembolso' => $this->devolucion_permite_reembolso,
            'permite_excepcion' => $this->devolucion_permite_excepcion,
            'zona_horaria' => (string) config('app.timezone'),
        ];
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
