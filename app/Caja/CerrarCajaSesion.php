<?php

namespace App\Caja;

use App\CajaChica\Permisos;
use App\Compras\Montos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\EstadoCajaSesion;
use App\Enums\MetodoPago;
use App\Enums\TipoCajaChicaMovimiento;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\CajaSesionMedio;
use App\Models\TenantUser;
use App\Tenancy\AccesoSucursales;
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
            $acceso = app(AccesoSucursales::class);
            abort_unless($acceso->autoriza($usuario, (int) $sesion->sucursal_id), 403);

            if (! $sesion->aceptaConteo()) {
                throw ValidationException::withMessages([
                    'estado_sesion' => 'Esa caja ya está cerrada.',
                ]);
            }

            $esDueno = (int) $sesion->user_id === (int) $usuario->id;

            if (! $esDueno) {
                if (! $usuario->can(Permisos::CERRAR_AJENO) || ! app(AccesoSucursales::class)->autoriza($usuario, (int) $sesion->sucursal_id)) {
                    throw ValidationException::withMessages([
                        'estado_sesion' => 'No podés cerrar el turno de otra persona.',
                    ]);
                }
            }

            $arqueo = $this->arqueo->de($sesion);
            $this->autorizarValesPendientes($sesion, $arqueo, $data, $usuario, $esDueno);

            if ($this->sinMovimientos($arqueo)) {
                $sesion->fill([
                    'estado_sesion' => EstadoCajaSesion::Anulada,
                    'fecha_cierre' => now(),
                    'monto_cierre_teorico' => $arqueo['teorico_efectivo'],
                    'monto_cierre_real' => $arqueo['teorico_efectivo'],
                    'diferencia' => '0.00',
                    'justificacion_diferencia' => null,
                    'supervisor_id' => null,
                    'cerrado_por_user_id' => $usuario->id,
                ]);
                $sesion->save();

                return $sesion->refresh();
            }

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
                'cerrado_por_user_id' => $usuario->id,
                'cierre_vales_autorizado_por_id' => $arqueo['vales_pendientes'] !== [] ? $usuario->id : null,
                'cierre_vales_motivo' => $arqueo['vales_pendientes'] !== [] ? $this->justificacion($data['cierre_vales_motivo'] ?? null) : null,
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

    /**
     * @param  array{total_cobros: string, total_reembolsos: string}  $arqueo
     */
    private function sinMovimientos(array $arqueo): bool
    {
        return Montos::comparar($arqueo['total_cobros'], '0', 2) === 0
            && Montos::comparar($arqueo['total_reembolsos'], '0', 2) === 0
            && ! ($arqueo['tiene_caja_chica'] ?? false);
    }

    /**
     * @param  array{vales_pendientes: list<array{id: int, monto: string}>}  $arqueo
     * @param  array<string, mixed>  $data
     */
    private function autorizarValesPendientes(CajaSesion $sesion, array $arqueo, array $data, TenantUser $usuario, bool $esDueno): void
    {
        if ($arqueo['vales_pendientes'] === []) {
            return;
        }

        $motivo = $this->justificacion($data['cierre_vales_motivo'] ?? null);
        $seguimiento = isset($data['seguimiento_user_id']) ? (int) $data['seguimiento_user_id'] : 0;
        $puede = $usuario->can(Permisos::REVISAR)
            && ($esDueno || app(AccesoSucursales::class)->autoriza($usuario, (int) $sesion->sucursal_id));

        if (! $puede || $motivo === null || $seguimiento < 1) {
            $total = '0.00';

            foreach ($arqueo['vales_pendientes'] as $vale) {
                $total = Montos::sumar($total, $vale['monto'], 2);
            }

            throw ValidationException::withMessages([
                'cierre_vales_motivo' => 'Hay vales pendientes por '.$total.'. El cierre pide permiso de revisión, un motivo y un responsable de seguimiento.',
            ]);
        }

        $responsable = TenantUser::query()->whereKey($seguimiento)->first();

        if (! $responsable instanceof TenantUser) {
            throw ValidationException::withMessages([
                'seguimiento_user_id' => 'El responsable de seguimiento no es de este negocio.',
            ]);
        }

        CajaChicaMovimiento::query()
            ->where('caja_sesion_id', $sesion->id)
            ->where('tipo', TipoCajaChicaMovimiento::EgresoVale)
            ->where('estado', EstadoCajaChicaMovimiento::PendienteRendir)
            ->update(['seguimiento_user_id' => $responsable->id]);
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
