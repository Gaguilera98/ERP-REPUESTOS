<?php

namespace App\Caja;

use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Models\CajaSesion;
use App\Models\CajaSesionMedio;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CerrarCajaSesion
{
    public function __construct(
        private ArqueoCajaSesion $arqueo,
    ) {}

    /**
     * @param  array{
     *     medios_declarados?: mixed,
     *     monto_cierre_real?: mixed,
     *     justificacion_diferencia?: mixed
     * }  $data
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

            $arqueo = $this->arqueo->de($sesion);
            $declarados = $this->mediosDeclarados($data, $arqueo);
            $justificacion = $this->justificacion($data['justificacion_diferencia'] ?? null);
            $hayDiferencia = false;
            $filas = [];

            foreach ($arqueo['por_metodo'] as $fila) {
                $metodo = $fila['metodo'];
                $esperado = $fila['esperado'];
                $declarado = $declarados[$metodo];
                $diferencia = Montos::dos(bcsub($declarado, $esperado, 4));

                if (Montos::comparar($diferencia, '0', 2) !== 0) {
                    $hayDiferencia = true;
                }

                $filas[] = [
                    'metodo_pago' => $metodo,
                    'monto_esperado' => $esperado,
                    'monto_declarado' => $declarado,
                    'diferencia' => $diferencia,
                ];
            }

            if ($hayDiferencia && $justificacion === null) {
                throw ValidationException::withMessages([
                    'justificacion_diferencia' => 'Si algún medio no calza con lo esperado, tenés que justificarlo.',
                ]);
            }

            $efectivo = collect($filas)->firstWhere('metodo_pago', MetodoPago::Efectivo->value);
            $teorico = $efectivo['monto_esperado'];
            $real = $efectivo['monto_declarado'];
            $diferenciaEfectivo = $efectivo['diferencia'];

            $sesion->fill([
                'estado_sesion' => EstadoCajaSesion::Cerrada,
                'fecha_cierre' => now(),
                'monto_cierre_teorico' => $teorico,
                'monto_cierre_real' => $real,
                'diferencia' => $diferenciaEfectivo,
                'justificacion_diferencia' => $hayDiferencia ? $justificacion : null,
                'supervisor_id' => $hayDiferencia && $usuario->hasRole('supervisor')
                    ? $usuario->id
                    : null,
            ]);
            $sesion->save();

            foreach ($filas as $fila) {
                CajaSesionMedio::query()->create([
                    'caja_sesion_id' => $sesion->id,
                    ...$fila,
                ]);
            }

            return $sesion->refresh()->load('medios');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{por_metodo: list<array{metodo: string, esperado: string}>}  $arqueo
     * @return array<string, string>
     */
    private function mediosDeclarados(array $data, array $arqueo): array
    {
        $entrada = $data['medios_declarados'] ?? null;

        if (! is_array($entrada)) {
            $entrada = [];
        }

        // Compatibilidad: un solo campo de efectivo contado.
        if (! array_key_exists(MetodoPago::Efectivo->value, $entrada) && array_key_exists('monto_cierre_real', $data)) {
            $entrada[MetodoPago::Efectivo->value] = $data['monto_cierre_real'];
        }

        $declarados = [];

        foreach ($arqueo['por_metodo'] as $fila) {
            $metodo = $fila['metodo'];
            $campo = "medios_declarados.{$metodo}";

            if (! array_key_exists($metodo, $entrada)) {
                throw ValidationException::withMessages([
                    $campo => 'Declará el monto de '.$fila['etiqueta'].'.',
                ]);
            }

            $declarados[$metodo] = $this->monto($entrada[$metodo], $campo);
        }

        return $declarados;
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
                $campo => 'El monto declarado tiene que ser un número de cero para arriba.',
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
