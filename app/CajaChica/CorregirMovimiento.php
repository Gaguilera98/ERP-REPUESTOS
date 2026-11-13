<?php

namespace App\CajaChica;

use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\SentidoCajaChica;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorregirMovimiento
{
    public function __construct(
        private GuardarMovimiento $guardar,
        private AccesoSucursales $acceso,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function corregir(CajaChicaMovimiento $original, array $data, TenantUser $actor): CajaChicaMovimiento
    {
        if (! $actor->can(Permisos::REVISAR)) {
            throw ValidationException::withMessages([
                'motivo' => 'No podés corregir movimientos de caja chica.',
            ]);
        }

        if (! $this->acceso->autoriza($actor, (int) $original->sucursal_id)) {
            $fondo = $original->fondo;
            $esResponsable = $fondo instanceof CajaChicaFondo && (int) $fondo->responsable_id === (int) $actor->id;

            if (! $esResponsable) {
                throw ValidationException::withMessages([
                    'origen' => 'No tenés esa sucursal asignada.',
                ]);
            }
        }

        $motivo = is_string($data['motivo'] ?? null) ? trim($data['motivo']) : '';

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'La corrección necesita un motivo.',
            ]);
        }

        $clase = ($data['clase'] ?? 'error') === 'devolucion' ? 'devolucion' : 'error';

        return DB::transaction(function () use ($original, $data, $actor, $motivo, $clase): CajaChicaMovimiento {
            $original = CajaChicaMovimiento::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($original->estado === EstadoCajaChicaMovimiento::Corregido || $original->tipo === TipoCajaChicaMovimiento::Correccion) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Ese registro ya fue corregido.',
                ]);
            }

            $sesion = $original->caja_sesion_id
                ? CajaSesion::query()->whereKey($original->caja_sesion_id)->first()
                : null;
            $cerrada = $sesion instanceof CajaSesion && ! $sesion->aceptaConteo();
            $original->estado = EstadoCajaChicaMovimiento::Corregido;
            $original->save();

            $auditoria = $this->guardar->crear([
                'tipo' => TipoCajaChicaMovimiento::Correccion,
                'sentido' => SentidoCajaChica::Entrada,
                'estado' => EstadoCajaChicaMovimiento::Corregido,
                'afecta_saldo' => false,
                'monto' => $original->monto,
                'concepto' => $clase === 'devolucion' ? 'Devolución identificada' : 'Corrección de registro',
                'motivo_excepcion' => $motivo,
                'movimiento_origen_id' => $original->id,
                'caja_sesion_id' => $original->caja_sesion_id,
                'fondo_id' => $original->fondo_id,
            ], $actor);

            $revierteEnOrigen = $clase === 'error' && ! $cerrada && $original->afecta_saldo;

            if ($revierteEnOrigen) {
                $sentido = $original->sentido === SentidoCajaChica::Salida
                    ? SentidoCajaChica::Entrada
                    : SentidoCajaChica::Salida;

                return $this->guardar->crear([
                    'tipo' => TipoCajaChicaMovimiento::Correccion,
                    'sentido' => $sentido,
                    'estado' => EstadoCajaChicaMovimiento::Corregido,
                    'afecta_saldo' => true,
                    'monto' => $original->monto,
                    'concepto' => 'Ajuste del registro corregido',
                    'motivo_excepcion' => $motivo,
                    'movimiento_origen_id' => $original->id,
                    'caja_sesion_id' => $original->caja_sesion_id,
                    'fondo_id' => $original->fondo_id,
                ], $actor);
            }

            if ($clase === 'devolucion') {
                $destinoSesion = isset($data['destino_caja_sesion_id']) ? (int) $data['destino_caja_sesion_id'] : 0;
                $destinoFondo = isset($data['destino_fondo_id']) ? (int) $data['destino_fondo_id'] : 0;

                if ($destinoSesion < 1 && $destinoFondo < 1) {
                    throw ValidationException::withMessages([
                        'destino' => 'La devolución de efectivo se registra en la caja que la recibe.',
                    ]);
                }

                return $this->guardar->crear([
                    'tipo' => TipoCajaChicaMovimiento::Correccion,
                    'sentido' => SentidoCajaChica::Entrada,
                    'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                    'afecta_saldo' => true,
                    'monto' => $original->monto,
                    'concepto' => 'Devolución de efectivo',
                    'motivo_excepcion' => $motivo,
                    'movimiento_origen_id' => $original->id,
                    'caja_sesion_id' => $destinoSesion > 0 ? $destinoSesion : null,
                    'fondo_id' => $destinoFondo > 0 ? $destinoFondo : null,
                ], $actor);
            }

            return $auditoria;
        });
    }
}
