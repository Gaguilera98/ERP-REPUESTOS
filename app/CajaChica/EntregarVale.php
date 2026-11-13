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
use Illuminate\Validation\ValidationException;

class EntregarVale
{
    public function __construct(
        private AutorizarOrigen $autorizar,
        private GuardarMovimiento $guardar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function entregar(array $data, TenantUser $actor): CajaChicaMovimiento
    {
        $sesionId = $this->entero($data['caja_sesion_id'] ?? null);
        $fondoId = $this->entero($data['fondo_id'] ?? null);
        $sesion = null;
        $fondo = null;

        if ($sesionId !== null) {
            $sesion = CajaSesion::query()->whereKey($sesionId)->firstOrFail();
            $this->autorizar->sesionParaSalida($sesion, $actor);
        } else {
            $fondo = CajaChicaFondo::query()->whereKey($fondoId)->firstOrFail();
            $this->autorizar->fondoParaOperar($fondo, $actor);
        }

        $persona = is_string($data['persona_recibe'] ?? null) ? trim($data['persona_recibe']) : '';
        $concepto = is_string($data['concepto'] ?? null) ? trim($data['concepto']) : '';

        if ($persona === '' || $concepto === '') {
            throw ValidationException::withMessages([
                'persona_recibe' => 'El vale necesita la persona que recibe el efectivo y un concepto.',
            ]);
        }

        return $this->guardar->crear([
            'tipo' => TipoCajaChicaMovimiento::EgresoVale,
            'sentido' => SentidoCajaChica::Salida,
            'estado' => EstadoCajaChicaMovimiento::PendienteRendir,
            'afecta_saldo' => true,
            'monto' => Montos::dos((string) ($data['monto'] ?? '0')),
            'concepto' => $concepto,
            'persona_recibe' => $persona,
            'caja_sesion_id' => $sesion?->id,
            'fondo_id' => $fondo?->id,
            'idempotency_key' => is_string($data['idempotency_key'] ?? null) && trim($data['idempotency_key']) !== ''
                ? trim($data['idempotency_key'])
                : null,
        ], $actor);
    }

    private function entero(mixed $valor): ?int
    {
        if (is_int($valor) && $valor > 0) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        return null;
    }
}
