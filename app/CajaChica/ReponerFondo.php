<?php

namespace App\CajaChica;

use App\Compras\Montos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\SentidoCajaChica;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReponerFondo
{
    public function __construct(
        private AutorizarOrigen $autorizar,
        private GuardarMovimiento $guardar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function reponer(CajaChicaFondo $fondo, array $data, TenantUser $actor): CajaChicaMovimiento
    {
        return DB::transaction(function () use ($fondo, $data, $actor): CajaChicaMovimiento {
            $fondo = CajaChicaFondo::query()->whereKey($fondo->id)->lockForUpdate()->firstOrFail();
            $puede = ($actor->can(Permisos::OPERAR) && (int) $fondo->responsable_id === (int) $actor->id)
                || $actor->can(Permisos::FONDOS);

            if (! $puede) {
                throw ValidationException::withMessages([
                    'fondo' => 'No podés reponer ese fondo.',
                ]);
            }

            if ($actor->can(Permisos::FONDOS) && ! $actor->can(Permisos::OPERAR)) {
                $this->autorizar->sucursalAsignada($actor, (int) $fondo->sucursal_id);
            }

            $monto = Montos::dos((string) ($data['monto'] ?? '0'));
            $procedencia = is_string($data['procedencia'] ?? null) ? trim($data['procedencia']) : '';
            $concepto = is_string($data['concepto'] ?? null) && trim($data['concepto']) !== ''
                ? trim($data['concepto'])
                : 'Reposición de caja chica';

            if ($procedencia === '') {
                throw ValidationException::withMessages([
                    'procedencia' => 'Indicá de dónde sale el efectivo.',
                ]);
            }

            $sesionId = isset($data['caja_sesion_id']) ? (int) $data['caja_sesion_id'] : 0;
            $fondoOrigenId = isset($data['fondo_origen_id']) ? (int) $data['fondo_origen_id'] : 0;

            if ($sesionId > 0 || $fondoOrigenId > 0) {
                $egreso = $this->salida($sesionId, $fondoOrigenId, $monto, $concepto, $procedencia, $actor);

                return $this->guardar->crear([
                    'tipo' => TipoCajaChicaMovimiento::IngresoReposicion,
                    'sentido' => SentidoCajaChica::Entrada,
                    'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                    'monto' => $monto,
                    'concepto' => $concepto,
                    'procedencia' => $procedencia,
                    'fondo_id' => $fondo->id,
                    'movimiento_origen_id' => $egreso->id,
                ], $actor);
            }

            return $this->guardar->crear([
                'tipo' => TipoCajaChicaMovimiento::IngresoReposicion,
                'sentido' => SentidoCajaChica::Entrada,
                'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                'monto' => $monto,
                'concepto' => $concepto,
                'procedencia' => $procedencia,
                'fondo_id' => $fondo->id,
            ], $actor);
        });
    }

    private function salida(int $sesionId, int $fondoOrigenId, string $monto, string $concepto, string $procedencia, TenantUser $actor): CajaChicaMovimiento
    {
        if ($sesionId > 0) {
            $sesion = CajaSesion::query()->whereKey($sesionId)->firstOrFail();
            $this->autorizar->sesionParaSalida($sesion, $actor);

            return $this->guardar->crear([
                'tipo' => TipoCajaChicaMovimiento::EgresoReposicion,
                'sentido' => SentidoCajaChica::Salida,
                'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                'monto' => $monto,
                'concepto' => $concepto,
                'procedencia' => $procedencia,
                'caja_sesion_id' => $sesion->id,
            ], $actor);
        }

        $origen = CajaChicaFondo::query()->whereKey($fondoOrigenId)->firstOrFail();
        $this->autorizar->fondoParaOperar($origen, $actor);

        return $this->guardar->crear([
            'tipo' => TipoCajaChicaMovimiento::EgresoReposicion,
            'sentido' => SentidoCajaChica::Salida,
            'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
            'monto' => $monto,
            'concepto' => $concepto,
            'procedencia' => $procedencia,
            'fondo_id' => $origen->id,
        ], $actor);
    }
}
