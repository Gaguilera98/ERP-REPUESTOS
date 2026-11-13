<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Enums\SentidoCajaChica;
use App\Enums\SentidoPago;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\CajaChicaMovimiento;
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
     *     egresos_caja_chica: string,
     *     ingresos_caja_chica: string,
     *     gastos_caja_chica: string,
     *     vales_entregados: string,
     *     sobrantes_caja_chica: string,
     *     tiene_caja_chica: bool,
     *     vales_pendientes: list<array{id: int, monto: string, concepto: string, persona: string|null}>,
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
        $cajaChica = $this->cajaChica($sesion);
        $teoricoEfectivo = Montos::dos(bcsub(
            Montos::sumar(Montos::sumar($apertura, $cobrosEfectivo, 2), $cajaChica['ingresos'], 2),
            Montos::sumar($reembolsosEfectivo, $cajaChica['egresos'], 2),
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
            'egresos_caja_chica' => $cajaChica['egresos'],
            'ingresos_caja_chica' => $cajaChica['ingresos'],
            'gastos_caja_chica' => $cajaChica['gastos'],
            'vales_entregados' => $cajaChica['vales'],
            'sobrantes_caja_chica' => $cajaChica['sobrantes'],
            'tiene_caja_chica' => $cajaChica['tiene'],
            'vales_pendientes' => $cajaChica['pendientes'],
            'teorico_efectivo' => $teoricoEfectivo,
            'total_cobros' => $totalCobros,
            'total_reembolsos' => $totalReembolsos,
            'por_metodo' => $porMetodo,
        ];
    }

    /**
     * @return array{egresos: string, ingresos: string, gastos: string, vales: string, sobrantes: string, tiene: bool, pendientes: list<array{id: int, monto: string, concepto: string, persona: string|null}>}
     */
    private function cajaChica(CajaSesion $sesion): array
    {
        $movimientos = CajaChicaMovimiento::query()
            ->where('caja_sesion_id', $sesion->id)
            ->get(['id', 'tipo', 'sentido', 'estado', 'afecta_saldo', 'monto', 'concepto', 'persona_recibe']);

        $egresos = '0.00';
        $ingresos = '0.00';
        $gastos = '0.00';
        $vales = '0.00';
        $sobrantes = '0.00';
        $pendientes = [];

        foreach ($movimientos as $movimiento) {
            $monto = Montos::dos((string) $movimiento->monto);

            if ($movimiento->tipo === TipoCajaChicaMovimiento::EgresoGasto) {
                $gastos = Montos::sumar($gastos, $monto, 2);
            }

            if ($movimiento->tipo === TipoCajaChicaMovimiento::EgresoVale) {
                $vales = Montos::sumar($vales, $monto, 2);

                if ($movimiento->estado === EstadoCajaChicaMovimiento::PendienteRendir) {
                    $pendientes[] = [
                        'id' => (int) $movimiento->id,
                        'monto' => $monto,
                        'concepto' => (string) $movimiento->concepto,
                        'persona' => $movimiento->persona_recibe,
                    ];
                }
            }

            if ($movimiento->tipo === TipoCajaChicaMovimiento::IngresoSobrante) {
                $sobrantes = Montos::sumar($sobrantes, $monto, 2);
            }

            if (! $movimiento->afecta_saldo) {
                continue;
            }

            if ($movimiento->sentido === SentidoCajaChica::Salida) {
                $egresos = Montos::sumar($egresos, $monto, 2);
            } else {
                $ingresos = Montos::sumar($ingresos, $monto, 2);
            }
        }

        return [
            'egresos' => $egresos,
            'ingresos' => $ingresos,
            'gastos' => $gastos,
            'vales' => $vales,
            'sobrantes' => $sobrantes,
            'tiene' => $movimientos->isNotEmpty(),
            'pendientes' => $pendientes,
        ];
    }
}
