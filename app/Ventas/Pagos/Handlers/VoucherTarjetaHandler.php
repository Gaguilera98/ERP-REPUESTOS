<?php

namespace App\Ventas\Pagos\Handlers;

use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Ventas\Pagos\Concerns\PreparaPagoBase;
use App\Ventas\Pagos\MetodoPagoHandler;

class VoucherTarjetaHandler implements MetodoPagoHandler
{
    use PreparaPagoBase;

    public function metodo(): MetodoPago
    {
        return MetodoPago::VoucherTarjeta;
    }

    public function preparar(array $pago, int $indice, CajaSesion $caja): array
    {
        $preparado = $this->base($pago, $indice, $caja, $this->metodo());
        $preparado['numero_referencia_banco'] = $this->exigirTexto(
            $pago['numero_referencia_banco'] ?? null,
            "pagos.{$indice}.numero_referencia_banco",
            'Anotá el número del voucher.',
        );

        return $preparado;
    }
}
