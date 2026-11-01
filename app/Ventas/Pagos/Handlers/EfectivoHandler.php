<?php

namespace App\Ventas\Pagos\Handlers;

use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Ventas\Pagos\Concerns\PreparaPagoBase;
use App\Ventas\Pagos\MetodoPagoHandler;

class EfectivoHandler implements MetodoPagoHandler
{
    use PreparaPagoBase;

    public function metodo(): MetodoPago
    {
        return MetodoPago::Efectivo;
    }

    public function preparar(array $pago, int $indice, CajaSesion $caja): array
    {
        return $this->base($pago, $indice, $caja, $this->metodo());
    }
}
