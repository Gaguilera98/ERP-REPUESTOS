<?php

namespace App\CajaChica;

use App\Compras\Montos;
use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\SentidoCajaChica;
use App\Enums\TipoCajaChicaMovimiento;
use App\Enums\TipoComprobanteCajaChica;
use App\Models\CajaChicaFondo;
use App\Models\CajaChicaMovimiento;
use App\Models\CajaSesion;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RendirVale
{
    public function __construct(
        private AutorizarOrigen $autorizar,
        private GuardarMovimiento $guardar,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function rendir(CajaChicaMovimiento $vale, array $data, TenantUser $actor): CajaChicaMovimiento
    {
        return DB::transaction(function () use ($vale, $data, $actor): CajaChicaMovimiento {
            $vale = CajaChicaMovimiento::query()->whereKey($vale->id)->lockForUpdate()->firstOrFail();
            $this->autorizar->exigirAccesoARendicion($vale, $actor, 'vale_id');

            if ($vale->tipo !== TipoCajaChicaMovimiento::EgresoVale || $vale->estado !== EstadoCajaChicaMovimiento::PendienteRendir) {
                throw ValidationException::withMessages([
                    'vale' => 'Ese vale ya no está pendiente de rendir.',
                ]);
            }

            $gasto = Montos::dos((string) ($data['monto_gasto'] ?? '0'));
            $sobrante = Montos::dos((string) ($data['monto_sobrante'] ?? '0'));

            if (Montos::comparar(Montos::sumar($gasto, $sobrante, 2), (string) $vale->monto, 2) !== 0) {
                throw ValidationException::withMessages([
                    'monto_gasto' => 'El gasto más el sobrante tiene que igualar el vale. Lo que se gastó de más queda fuera de esta rendición.',
                ]);
            }

            $categoriaId = isset($data['categoria_gasto_id']) && (int) $data['categoria_gasto_id'] > 0
                ? (int) $data['categoria_gasto_id']
                : null;
            $this->autorizar->categoria($categoriaId);
            $ordenId = isset($data['orden_compra_id']) && (int) $data['orden_compra_id'] > 0
                ? (int) $data['orden_compra_id']
                : null;
            $this->autorizar->compra($ordenId);

            if (Montos::comparar($sobrante, '0', 2) === 1) {
                $destino = $this->destino($data, $actor);
                $this->guardar->crear([
                    'tipo' => TipoCajaChicaMovimiento::IngresoSobrante,
                    'sentido' => SentidoCajaChica::Entrada,
                    'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                    'afecta_saldo' => true,
                    'monto' => $sobrante,
                    'concepto' => 'Sobrante del vale '.$vale->id,
                    'movimiento_origen_id' => $vale->id,
                    'caja_sesion_id' => $destino['sesion']?->id,
                    'fondo_id' => $destino['fondo']?->id,
                    'categoria_gasto_id' => $categoriaId,
                ], $actor);
            }

            $vale->fill([
                'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
                'monto_gasto' => $gasto,
                'monto_sobrante' => $sobrante,
                'rendido_por_user_id' => $actor->id,
                'categoria_gasto_id' => $categoriaId ?? $vale->categoria_gasto_id,
                'orden_compra_id' => $ordenId ?? $vale->orden_compra_id,
                'tipo_comprobante' => $this->comprobante($data['tipo_comprobante'] ?? null),
                'numero_comprobante' => $this->opcional($data['numero_comprobante'] ?? null),
                'comprobante_archivo_path' => $this->opcional($data['comprobante_archivo_path'] ?? null),
            ]);
            $vale->save();

            return $vale->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sesion: ?CajaSesion, fondo: ?CajaChicaFondo}
     */
    private function destino(array $data, TenantUser $actor): array
    {
        $sesionId = isset($data['destino_caja_sesion_id']) ? (int) $data['destino_caja_sesion_id'] : 0;
        $fondoId = isset($data['destino_fondo_id']) ? (int) $data['destino_fondo_id'] : 0;

        if ($sesionId > 0) {
            $sesion = CajaSesion::query()->whereKey($sesionId)->firstOrFail();
            $this->autorizar->sesionParaEntrada($sesion, $actor);

            return ['sesion' => $sesion, 'fondo' => null];
        }

        $fondo = CajaChicaFondo::query()->whereKey($fondoId)->firstOrFail();
        $this->autorizar->fondoParaOperar($fondo, $actor);

        return ['sesion' => null, 'fondo' => $fondo];
    }

    private function comprobante(mixed $valor): ?TipoComprobanteCajaChica
    {
        return is_string($valor) ? TipoComprobanteCajaChica::tryFrom($valor) : null;
    }

    private function opcional(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return trim($valor);
    }
}
