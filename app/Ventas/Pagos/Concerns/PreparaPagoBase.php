<?php

namespace App\Ventas\Pagos\Concerns;

use App\Compras\Montos;
use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\NegocioConfiguracion;
use Illuminate\Validation\ValidationException;

trait PreparaPagoBase
{
    /**
     * @param  array<string, mixed>  $pago
     * @return array{
     *     metodo_pago: MetodoPago,
     *     monto: string,
     *     caja_sesion_id: int|null,
     *     numero_referencia_banco: string|null,
     *     qr_id_transaccion_banco: string|null,
     *     comprobante_archivo_path: string|null
     * }
     */
    protected function base(array $pago, int $indice, CajaSesion $caja, MetodoPago $metodo): array
    {
        $monto = $this->montoDos($pago['monto'] ?? null, "pagos.{$indice}.monto");

        if (Montos::comparar($monto, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                "pagos.{$indice}.monto" => 'El monto del cobro tiene que ser mayor a cero.',
            ]);
        }

        $referencia = $pago['numero_referencia_banco'] ?? null;
        $referencia = is_string($referencia) ? trim($referencia) : null;
        $qr = $pago['qr_id_transaccion_banco'] ?? null;
        $qr = is_string($qr) ? trim($qr) : null;
        $path = $pago['comprobante_archivo_path'] ?? null;
        $path = is_string($path) ? trim($path) : null;
        $path = $path !== '' ? $path : null;

        if ($metodo->aceptaComprobanteArchivo()
            && NegocioConfiguracion::query()->first()?->exigeComprobanteArchivo($metodo)
            && $path === null) {
            throw ValidationException::withMessages([
                "pagos.{$indice}.comprobante" => 'Adjuntá el comprobante (PDF o imagen).',
            ]);
        }

        return [
            'metodo_pago' => $metodo,
            'monto' => $monto,
            // Todo cobro del turno cuelga de la sesión abierta (arqueo). El efectivo es el que mueve el cajón.
            'caja_sesion_id' => $caja->id,
            'numero_referencia_banco' => $referencia !== '' ? $referencia : null,
            'qr_id_transaccion_banco' => $qr !== '' ? $qr : null,
            'comprobante_archivo_path' => $path,
        ];
    }

    protected function montoDos(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 2, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El monto tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::dos($texto);
    }

    protected function exigirTexto(mixed $valor, string $campo, string $mensaje): string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        if ($texto === '') {
            throw ValidationException::withMessages([
                $campo => $mensaje,
            ]);
        }

        return $texto;
    }
}
