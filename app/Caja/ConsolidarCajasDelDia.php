<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\Sucursal;
use App\Models\Venta;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConsolidarCajasDelDia
{
    public function __construct(
        private ArqueoCajaSesion $arqueo,
    ) {}

    /**
     * Totales del día por sucursal. El faltante solo sale de turnos ya cerrados.
     *
     * @return array{
     *     sucursal_id: int,
     *     sucursal: string,
     *     fecha: string,
     *     sesiones_abiertas: int,
     *     sesiones_pendientes: int,
     *     sesiones_cerradas: int,
     *     turnos_vacios_ocultos: int,
     *     total_apertura: string,
     *     total_cobros: string,
     *     total_reembolsos: string,
     *     pendiente_contar: string,
     *     faltante_cerrado: string,
     *     por_metodo: list<array{
     *         metodo: string,
     *         etiqueta: string,
     *         esperado: string,
     *         declarado: string|null,
     *         diferencia: string|null,
     *         pendiente: string
     *     }>,
     *     sesiones: list<array{
     *         id: int,
     *         user_id: int,
     *         cajero: string,
     *         estado: string,
     *         etiqueta: string,
     *         abierta: bool,
     *         pendiente: bool,
     *         fecha_apertura: string|null,
     *         fecha_cierre: string|null,
     *         monto_apertura: string,
     *         teorico_efectivo: string,
     *         declarado_efectivo: string|null,
     *         diferencia_efectivo: string|null,
     *         sin_declaracion: bool,
     *         total_cobros: string,
     *         justificacion: string|null,
     *         ventas: list<array{codigo: string, hora: string|null, total: string}>
     *     }>
     * }
     */
    public function de(Sucursal|int $sucursal, CarbonInterface|string $fecha): array
    {
        $sucursal = $sucursal instanceof Sucursal
            ? $sucursal
            : Sucursal::query()->findOrFail($sucursal);

        $dia = $fecha instanceof CarbonInterface
            ? Carbon::instance($fecha)->startOfDay()
            : Carbon::parse($fecha)->startOfDay();

        /** @var Collection<int, CajaSesion> $sesiones */
        $sesiones = CajaSesion::query()
            ->with(['usuario', 'medios', 'pagos.aplicaciones.venta'])
            ->where('sucursal_id', $sucursal->id)
            ->where('estado_sesion', '!=', EstadoCajaSesion::Anulada)
            ->whereDate('fecha_apertura', $dia->toDateString())
            ->orderBy('fecha_apertura')
            ->get();

        $esperadoCerrado = [];
        $declaradoPorMetodo = [];
        $pendientePorMetodo = [];
        $tieneDeclarado = [];

        foreach (MetodoPago::cases() as $metodo) {
            $esperadoCerrado[$metodo->value] = '0.00';
            $declaradoPorMetodo[$metodo->value] = '0.00';
            $pendientePorMetodo[$metodo->value] = '0.00';
            $tieneDeclarado[$metodo->value] = false;
        }

        $totalApertura = '0.00';
        $totalCobros = '0.00';
        $totalReembolsos = '0.00';
        $pendienteContar = '0.00';
        $faltanteCerrado = '0.00';
        $abiertas = 0;
        $pendientes = 0;
        $cerradas = 0;
        $vacios = 0;
        $filas = [];

        foreach ($sesiones as $sesion) {
            $arqueo = $this->arqueo->de($sesion);
            $vacio = Montos::comparar($arqueo['total_cobros'], '0', 2) === 0
                && Montos::comparar($arqueo['total_reembolsos'], '0', 2) === 0
                && ! ($arqueo['tiene_caja_chica'] ?? false)
                && $sesion->estado_sesion === EstadoCajaSesion::Cerrada;

            if ($vacio) {
                $vacios++;

                continue;
            }

            $totalApertura = Montos::sumar($totalApertura, $arqueo['apertura'], 2);
            $totalCobros = Montos::sumar($totalCobros, $arqueo['total_cobros'], 2);
            $totalReembolsos = Montos::sumar($totalReembolsos, $arqueo['total_reembolsos'], 2);

            $mediosEsperados = [];

            foreach ($arqueo['por_metodo'] as $fila) {
                $mediosEsperados[$fila['metodo']] = $fila['esperado'];
            }

            $declaradoEfectivo = null;
            $diferenciaEfectivo = null;
            $cuentaComoCerrada = $sesion->estado_sesion === EstadoCajaSesion::Cerrada;

            if ($cuentaComoCerrada) {
                $cerradas++;
                $mediosDeclarados = [];

                foreach ($sesion->medios as $medio) {
                    $clave = $medio->metodo_pago instanceof MetodoPago
                        ? $medio->metodo_pago->value
                        : (string) $medio->metodo_pago;
                    $mediosDeclarados[$clave] = Montos::dos((string) $medio->monto_declarado);
                    $declaradoPorMetodo[$clave] = Montos::sumar($declaradoPorMetodo[$clave] ?? '0.00', $mediosDeclarados[$clave], 2);
                    $tieneDeclarado[$clave] = true;
                }

                foreach ($mediosEsperados as $metodo => $esperado) {
                    $esperadoCerrado[$metodo] = Montos::sumar($esperadoCerrado[$metodo] ?? '0.00', $esperado, 2);
                }

                $declaradoEfectivo = $mediosDeclarados[MetodoPago::Efectivo->value]
                    ?? Montos::dos((string) ($sesion->monto_cierre_real ?? '0'));
                $diferenciaEfectivo = Montos::dos(bcsub(
                    $declaradoEfectivo,
                    $mediosEsperados[MetodoPago::Efectivo->value] ?? '0.00',
                    4,
                ));
            } else {
                if ($sesion->estaAbierta()) {
                    $abiertas++;
                } else {
                    $pendientes++;
                }

                foreach ($mediosEsperados as $metodo => $esperado) {
                    $pendientePorMetodo[$metodo] = Montos::sumar($pendientePorMetodo[$metodo] ?? '0.00', $esperado, 2);
                    $pendienteContar = Montos::sumar($pendienteContar, $esperado, 2);
                }
            }

            $filas[] = [
                'id' => (int) $sesion->id,
                'user_id' => (int) $sesion->user_id,
                'cajero' => $sesion->usuario?->name ?? '—',
                'estado' => $sesion->estado_sesion instanceof EstadoCajaSesion
                    ? $sesion->estado_sesion->value
                    : (string) $sesion->estado_sesion,
                'etiqueta' => $sesion->estado_sesion instanceof EstadoCajaSesion
                    ? $sesion->estado_sesion->etiqueta()
                    : (string) $sesion->estado_sesion,
                'abierta' => $sesion->estaAbierta(),
                'pendiente' => $sesion->estado_sesion === EstadoCajaSesion::PendienteArqueo,
                'fecha_apertura' => $sesion->fecha_apertura?->format('H:i'),
                'fecha_cierre' => $sesion->fecha_cierre?->format('H:i'),
                'monto_apertura' => $arqueo['apertura'],
                'teorico_efectivo' => $mediosEsperados[MetodoPago::Efectivo->value] ?? '0.00',
                'declarado_efectivo' => $declaradoEfectivo,
                'diferencia_efectivo' => $diferenciaEfectivo,
                'sin_declaracion' => $cuentaComoCerrada && $sesion->medios->isEmpty(),
                'total_cobros' => $arqueo['total_cobros'],
                'egresos_caja_chica' => $arqueo['egresos_caja_chica'],
                'ingresos_caja_chica' => $arqueo['ingresos_caja_chica'],
                'vales_pendientes' => $arqueo['vales_pendientes'],
                'justificacion' => $sesion->justificacion_diferencia,
                'ventas' => $this->ventasDe($sesion),
            ];
        }

        $porMetodo = [];

        foreach (MetodoPago::cases() as $metodo) {
            $esperado = $esperadoCerrado[$metodo->value];
            $declarado = $tieneDeclarado[$metodo->value]
                ? $declaradoPorMetodo[$metodo->value]
                : null;
            $diferencia = $declarado === null
                ? null
                : Montos::dos(bcsub($declarado, $esperado, 4));

            if ($diferencia !== null) {
                $faltanteCerrado = Montos::sumar($faltanteCerrado, $diferencia, 2);
            }

            $porMetodo[] = [
                'metodo' => $metodo->value,
                'etiqueta' => $metodo->etiqueta(),
                'esperado' => $esperado,
                'declarado' => $declarado,
                'diferencia' => $diferencia,
                'pendiente' => $pendientePorMetodo[$metodo->value],
            ];
        }

        return [
            'sucursal_id' => (int) $sucursal->id,
            'sucursal' => $sucursal->nombre,
            'fecha' => $dia->toDateString(),
            'sesiones_abiertas' => $abiertas,
            'sesiones_pendientes' => $pendientes,
            'sesiones_cerradas' => $cerradas,
            'turnos_vacios_ocultos' => $vacios,
            'total_apertura' => $totalApertura,
            'total_cobros' => $totalCobros,
            'total_reembolsos' => $totalReembolsos,
            'pendiente_contar' => $pendienteContar,
            'faltante_cerrado' => $faltanteCerrado,
            'por_metodo' => $porMetodo,
            'sesiones' => $filas,
        ];
    }

    /**
     * @return list<array{codigo: string, hora: string|null, total: string}>
     */
    private function ventasDe(CajaSesion $sesion): array
    {
        $vistas = [];

        foreach ($sesion->pagos as $pago) {
            foreach ($pago->aplicaciones as $aplicacion) {
                $venta = $aplicacion->venta;

                if ($aplicacion->es_saldo_favor || ! $venta instanceof Venta || isset($vistas[$venta->id])) {
                    continue;
                }

                $vistas[$venta->id] = [
                    'codigo' => $venta->codigo,
                    'hora' => $venta->created_at?->format('H:i'),
                    'total' => Montos::dos((string) $venta->total),
                ];
            }
        }

        return array_values($vistas);
    }
}
