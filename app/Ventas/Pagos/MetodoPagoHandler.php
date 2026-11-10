<?php

namespace App\Ventas\Pagos;

use App\Enums\MetodoPago;
use App\Models\CajaSesion;

interface MetodoPagoHandler
{
    public function metodo(): MetodoPago;

    /**
     * Normaliza y valida un cobro de este método.
     *
     * @param  array<string, mixed>  $pago
     * @return array{
     *     metodo_pago: MetodoPago,
     *     monto: string,
     *     efectivo_recibido?: string,
     *     cambio_entregado?: string,
     *     caja_sesion_id: int|null,
     *     numero_referencia_banco: string|null,
     *     qr_id_transaccion_banco: string|null,
     *     comprobante_archivo_path: string|null
     * }
     */
    public function preparar(array $pago, int $indice, CajaSesion $caja): array;
}
