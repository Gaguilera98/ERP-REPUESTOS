<?php

namespace App\Ventas\Pagos\Handlers;

use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Ventas\Pagos\Concerns\PreparaPagoBase;
use App\Ventas\Pagos\MetodoPagoHandler;
use Illuminate\Validation\ValidationException;

class EfectivoHandler implements MetodoPagoHandler
{
    use PreparaPagoBase;

    public function metodo(): MetodoPago
    {
        return MetodoPago::Efectivo;
    }

    public function preparar(array $pago, int $indice, CajaSesion $caja): array
    {
        $preparado = $this->base($pago, $indice, $caja, $this->metodo());
        $recibido = $pago['efectivo_recibido'] ?? null;
        $recibido = $recibido === null || $recibido === '' ? $preparado['monto'] : $recibido;
        validator(['pagos' => [$indice => ['efectivo_recibido' => $recibido]]], ["pagos.{$indice}.efectivo_recibido" => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/']])
            ->validate();
        $recibido = $this->montoDos($recibido, "pagos.{$indice}.efectivo_recibido");
        if (bccomp($recibido, $preparado['monto'], 2) < 0) {
            throw ValidationException::withMessages(["pagos.{$indice}.efectivo_recibido" => 'El efectivo recibido no alcanza para cubrir este cobro.']);
        }

        return [...$preparado, 'efectivo_recibido' => $recibido, 'cambio_entregado' => bcsub($recibido, $preparado['monto'], 2)];
    }
}
