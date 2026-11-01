<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CerrarCajaSesion
{
    /**
     * @param  array{monto_cierre_real: mixed, justificacion_diferencia?: mixed}  $data
     */
    public function cerrar(CajaSesion $sesion, array $data, TenantUser $usuario): CajaSesion
    {
        return DB::transaction(function () use ($sesion, $data, $usuario): CajaSesion {
            $sesion = CajaSesion::query()->whereKey($sesion->id)->lockForUpdate()->firstOrFail();

            if (! $sesion->estaAbierta()) {
                throw ValidationException::withMessages([
                    'estado_sesion' => 'Esa caja ya está cerrada.',
                ]);
            }

            if ((int) $sesion->user_id !== (int) $usuario->id) {
                throw ValidationException::withMessages([
                    'estado_sesion' => 'Solo cerrás la caja que abriste vos.',
                ]);
            }

            $teorico = Montos::dos((string) $sesion->monto_apertura);
            $real = $this->monto($data['monto_cierre_real'] ?? null, 'monto_cierre_real');
            $diferencia = Montos::dos(bcsub($real, $teorico, 4));
            $justificacion = $this->justificacion($data['justificacion_diferencia'] ?? null);

            if (Montos::comparar($diferencia, '0', 2) !== 0 && $justificacion === null) {
                throw ValidationException::withMessages([
                    'justificacion_diferencia' => 'Si hay diferencia, tenés que justificarla.',
                ]);
            }

            $sesion->fill([
                'estado_sesion' => EstadoCajaSesion::Cerrada,
                'fecha_cierre' => now(),
                'monto_cierre_teorico' => $teorico,
                'monto_cierre_real' => $real,
                'diferencia' => $diferencia,
                'justificacion_diferencia' => Montos::comparar($diferencia, '0', 2) === 0 ? null : $justificacion,
                'supervisor_id' => Montos::comparar($diferencia, '0', 2) !== 0 && $usuario->hasRole('supervisor')
                    ? $usuario->id
                    : null,
            ]);
            $sesion->save();

            return $sesion->refresh();
        });
    }

    private function monto(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 2, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El efectivo contado tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::dos($texto);
    }

    private function justificacion(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto !== '' ? $texto : null;
    }
}
