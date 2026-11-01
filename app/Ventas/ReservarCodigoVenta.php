<?php

namespace App\Ventas;

use App\Models\NegocioConfiguracion;
use RuntimeException;

class ReservarCodigoVenta
{
    /**
     * Reserva el siguiente VNT- con la fila de configuración bloqueada.
     */
    public function reservar(): string
    {
        $config = NegocioConfiguracion::query()->lockForUpdate()->first();

        if (! $config instanceof NegocioConfiguracion) {
            throw new RuntimeException('Falta la configuración del negocio.');
        }

        $numero = (int) $config->siguiente_numero_venta;
        $config->siguiente_numero_venta = $numero + 1;
        $config->save();

        return 'VNT-'.str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }
}
