<?php

namespace App\CajaChica;

use App\Compras\Montos;
use App\Enums\SentidoCajaChica;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardarMovimiento
{
    public function __construct(private SaldoEfectivo $saldo) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos, TenantUser $actor): CajaChicaMovimiento
    {
        $clave = isset($datos['idempotency_key']) && is_string($datos['idempotency_key']) && $datos['idempotency_key'] !== ''
            ? $datos['idempotency_key']
            : null;

        if ($clave !== null) {
            $ya = CajaChicaMovimiento::query()->where('idempotency_key', $clave)->first();

            if ($ya instanceof CajaChicaMovimiento) {
                return $ya;
            }
        }

        try {
            return DB::transaction(function () use ($datos, $actor, $clave): CajaChicaMovimiento {
                if ($clave !== null) {
                    $ya = CajaChicaMovimiento::query()->where('idempotency_key', $clave)->lockForUpdate()->first();

                    if ($ya instanceof CajaChicaMovimiento) {
                        return $ya;
                    }
                }

                $sesionId = $this->entero($datos['caja_sesion_id'] ?? null);
                $fondoId = $this->entero($datos['fondo_id'] ?? null);

                if (($sesionId === null) === ($fondoId === null)) {
                    throw ValidationException::withMessages([
                        'origen' => 'El movimiento tiene que salir de un turno o de un fondo, no de los dos.',
                    ]);
                }

                $afecta = (bool) ($datos['afecta_saldo'] ?? true);
                $sentido = $datos['sentido'] instanceof SentidoCajaChica
                    ? $datos['sentido']
                    : SentidoCajaChica::from((string) $datos['sentido']);
                $monto = Montos::dos((string) $datos['monto']);

                if ($afecta && Montos::comparar($monto, '0', 2) !== 1) {
                    throw ValidationException::withMessages([
                        'monto' => 'El monto tiene que ser mayor a cero.',
                    ]);
                }

                if ($sesionId !== null) {
                    $sesion = CajaSesion::query()->whereKey($sesionId)->lockForUpdate()->firstOrFail();
                    $this->exigirEfectivo($afecta, $sentido, $monto, $this->saldo->deSesion($sesion));
                    $datos['sucursal_id'] = $sesion->sucursal_id;
                    $datos['fondo_id'] = null;
                    $datos['caja_sesion_id'] = $sesion->id;
                } else {
                    $fondo = CajaChicaFondo::query()->whereKey($fondoId)->lockForUpdate()->firstOrFail();
                    $disponible = Montos::dos((string) $fondo->saldo_actual);
                    $this->exigirEfectivo($afecta, $sentido, $monto, $disponible);

                    if ($afecta) {
                        $fondo->saldo_actual = $sentido === SentidoCajaChica::Salida
                            ? Montos::dos(bcsub($disponible, $monto, 4))
                            : Montos::sumar($disponible, $monto, 2);
                        $fondo->save();
                    }

                    $datos['sucursal_id'] = $fondo->sucursal_id;
                    $datos['caja_sesion_id'] = null;
                    $datos['fondo_id'] = $fondo->id;
                }

                $datos['registrado_por_user_id'] = $datos['registrado_por_user_id'] ?? $actor->id;
                $datos['monto'] = $monto;
                $datos['sentido'] = $sentido;
                $datos['afecta_saldo'] = $afecta;
                $datos['idempotency_key'] = $clave;

                return CajaChicaMovimiento::query()->create($datos);
            });
        } catch (QueryException $exception) {
            if ($clave !== null && str_contains(strtolower($exception->getMessage()), 'idempotency')) {
                return CajaChicaMovimiento::query()->where('idempotency_key', $clave)->firstOrFail();
            }

            throw $exception;
        }
    }

    private function exigirEfectivo(bool $afecta, SentidoCajaChica $sentido, string $monto, string $disponible): void
    {
        if (! $afecta || $sentido !== SentidoCajaChica::Salida) {
            return;
        }

        if (Montos::comparar($disponible, '0', 2) !== 1) {
            throw ValidationException::withMessages([
                'monto' => 'No hay efectivo disponible para este gasto.',
            ]);
        }

        if (Montos::comparar($monto, $disponible, 2) === 1) {
            throw ValidationException::withMessages([
                'monto' => 'El monto supera el efectivo disponible.',
            ]);
        }
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
