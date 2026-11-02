<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Models\CajaSesion;
use App\Models\Pago;

class ArqueoCajaSesion
{
    /**
     * Resumen del turno: esperado por medio (efectivo incluye apertura) y totales.
     *
     * @return array{
     *     apertura: string,
     *     cobros_efectivo: string,
     *     reembolsos_efectivo: string,
     *     teorico_efectivo: string,
     *     total_cobros: string,
     *     total_reembolsos: string,
     *     por_metodo: list<array{
     *         metodo: string,
     *         etiqueta: string,
     *         cobros: string,
     *         reembolsos: string,
     *         neto: string,
     *         esperado: string
     *     }>
     * }
     */
    public function de(CajaSesion $sesion): array
    {
        $apertura = Montos::dos((string) $sesion->monto_apertura);

        $pagos = Pago::query()
            ->where('caja_sesion_id', $sesion->id)
            ->where('estado_pago', EstadoPago::Aprobado)
            ->get(['metodo_pago', 'sentido', 'monto']);

        $cobrosPorMetodo = [];
        $reembolsosPorMetodo = [];

        foreach (MetodoPago::cases() as $metodo) {
            $cobrosPorMetodo[$metodo->value] = '0.00';
            $reembolsosPorMetodo[$metodo->value] = '0.00';
        }

        foreach ($pagos as $pago) {
            $clave = $pago->metodo_pago instanceof MetodoPago
                ? $pago->metodo_pago->value
                : (string) $pago->metodo_pago;

            if (! array_key_exists($clave, $cobrosPorMetodo)) {
                $cobrosPorMetodo[$clave] = '0.00';
                $reembolsosPorMetodo[$clave] = '0.00';
            }

            $monto = Montos::dos((string) $pago->monto);

            if ($pago->sentido === SentidoPago::Reembolso) {
                $reembolsosPorMetodo[$clave] = Montos::sumar($reembolsosPorMetodo[$clave], $monto, 2);
            } else {
                $cobrosPorMetodo[$clave] = Montos::sumar($cobrosPorMetodo[$clave], $monto, 2);
            }
        }

        $cobrosEfectivo = $cobrosPorMetodo[MetodoPago::Efectivo->value] ?? '0.00';
        $reembolsosEfectivo = $reembolsosPorMetodo[MetodoPago::Efectivo->value] ?? '0.00';
        $teoricoEfectivo = Montos::dos(bcsub(
            Montos::sumar($apertura, $cobrosEfectivo, 2),
            $reembolsosEfectivo,
            4,
        ));

        $porMetodo = [];
        $totalCobros = '0.00';
        $totalReembolsos = '0.00';

        foreach (MetodoPago::cases() as $metodo) {
            $cobros = $cobrosPorMetodo[$metodo->value];
            $reembolsos = $reembolsosPorMetodo[$metodo->value];
            $neto = Montos::dos(bcsub($cobros, $reembolsos, 4));
            $esperado = $metodo === MetodoPago::Efectivo
                ? $teoricoEfectivo
                : $neto;

            $totalCobros = Montos::sumar($totalCobros, $cobros, 2);
            $totalReembolsos = Montos::sumar($totalReembolsos, $reembolsos, 2);

            $porMetodo[] = [
                'metodo' => $metodo->value,
                'etiqueta' => $metodo->etiqueta(),
                'cobros' => $cobros,
                'reembolsos' => $reembolsos,
                'neto' => $neto,
                'esperado' => $esperado,
            ];
        }

        return [
            'apertura' => $apertura,
            'cobros_efectivo' => $cobrosEfectivo,
            'reembolsos_efectivo' => $reembolsosEfectivo,
            'teorico_efectivo' => $teoricoEfectivo,
            'total_cobros' => $totalCobros,
            'total_reembolsos' => $totalReembolsos,
            'por_metodo' => $porMetodo,
        ];
    }
}
