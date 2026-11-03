<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Models\CajaSesion;
use App\Models\Sucursal;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AbrirCajaSesion
{
    /**
     * @param  array{sucursal_id: mixed, monto_apertura: mixed}  $data
     */
    public function abrir(array $data, TenantUser $usuario): CajaSesion
    {
        $sucursalId = $this->id($data['sucursal_id'] ?? null, 'sucursal_id', 'Elegí la sucursal.');
        $monto = $this->monto($data['monto_apertura'] ?? null, 'monto_apertura');

        if (! Sucursal::query()->whereKey($sucursalId)->exists()) {
            throw ValidationException::withMessages([
                'sucursal_id' => 'Esa sucursal no es de este negocio.',
            ]);
        }

        return DB::transaction(function () use ($sucursalId, $monto, $usuario): CajaSesion {
            app(CongelarTurnosVencidos::class)->de($usuario, $sucursalId);

            $yaAbierta = CajaSesion::query()
                ->where('sucursal_id', $sucursalId)
                ->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)
                ->lockForUpdate()
                ->exists();

            if ($yaAbierta) {
                throw ValidationException::withMessages([
                    'sucursal_id' => 'Ya tenés una caja abierta en esa sucursal.',
                ]);
            }

            return CajaSesion::query()->create([
                'sucursal_id' => $sucursalId,
                'user_id' => $usuario->id,
                'estado_sesion' => EstadoCajaSesion::Abierta,
                'fecha_apertura' => now(),
                'monto_apertura' => $monto,
                'diferencia' => '0.00',
            ]);
        });
    }

    private function id(mixed $valor, string $campo, string $mensaje): int
    {
        if (is_int($valor) && $valor > 0) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        throw ValidationException::withMessages([
            $campo => $mensaje,
        ]);
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
                $campo => 'El monto de apertura tiene que ser un número de cero para arriba.',
            ]);
        }

        $monto = Montos::dos($texto);

        if (Montos::comparar($monto, '0', 2) === -1) {
            throw ValidationException::withMessages([
                $campo => 'El monto de apertura tiene que ser un número de cero para arriba.',
            ]);
        }

        return $monto;
    }
}
