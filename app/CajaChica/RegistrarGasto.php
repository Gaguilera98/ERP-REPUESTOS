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
use Illuminate\Validation\ValidationException;

class RegistrarGasto
{
    public function __construct(
        private AutorizarOrigen $autorizar,
        private GuardarMovimiento $guardar,
        private SaldoEfectivo $saldo,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function registrar(array $data, TenantUser $actor): CajaChicaMovimiento
    {
        $origen = $this->origen($data, $actor);
        $categoriaId = $this->entero($data['categoria_gasto_id'] ?? null);
        $this->autorizar->categoria($categoriaId);
        $ordenId = $this->entero($data['orden_compra_id'] ?? null);
        $this->autorizar->compra($ordenId);

        $concepto = $this->texto($data['concepto'] ?? null, 'concepto', 'El concepto es obligatorio.');
        $monto = Montos::dos((string) ($data['monto'] ?? '0'));
        $sinComprobante = (bool) ($data['sin_comprobante'] ?? false);
        $motivo = $this->textoOpcional($data['motivo_excepcion'] ?? null);
        $estado = EstadoCajaChicaMovimiento::PendienteComprobante;
        $afecta = true;

        if ($sinComprobante) {
            if (! $actor->can(Permisos::REVISAR) || $motivo === null) {
                throw ValidationException::withMessages([
                    'motivo_excepcion' => 'Un gasto sin comprobante definitivo exige permiso de revisión y un motivo.',
                ]);
            }

            $estado = EstadoCajaChicaMovimiento::SinComprobanteAutorizado;
        }

        if ((bool) ($data['ya_ocurrido'] ?? false)) {
            $disponible = $origen['sesion'] instanceof CajaSesion
                ? $this->saldo->deSesion($origen['sesion'])
                : $this->saldo->deFondo($origen['fondo']);

            if (Montos::comparar($monto, $disponible, 2) === 1) {
                $afecta = false;
                $estado = EstadoCajaChicaMovimiento::EnRevision;
            }
        }

        return $this->guardar->crear([
            'tipo' => TipoCajaChicaMovimiento::EgresoGasto,
            'sentido' => SentidoCajaChica::Salida,
            'estado' => $estado,
            'afecta_saldo' => $afecta,
            'monto' => $monto,
            'concepto' => $concepto,
            'categoria_gasto_id' => $categoriaId,
            'orden_compra_id' => $ordenId,
            'caja_sesion_id' => $origen['sesion']?->id,
            'fondo_id' => $origen['fondo']?->id,
            'tipo_comprobante' => $this->comprobante($data['tipo_comprobante'] ?? null),
            'numero_comprobante' => $this->textoOpcional($data['numero_comprobante'] ?? null),
            'comprobante_archivo_path' => $this->textoOpcional($data['comprobante_archivo_path'] ?? null),
            'motivo_excepcion' => $motivo,
            'autorizado_por_user_id' => $sinComprobante ? $actor->id : null,
            'idempotency_key' => $this->textoOpcional($data['idempotency_key'] ?? null),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sesion: ?CajaSesion, fondo: ?CajaChicaFondo}
     */
    private function origen(array $data, TenantUser $actor): array
    {
        $sesionId = $this->entero($data['caja_sesion_id'] ?? null);
        $fondoId = $this->entero($data['fondo_id'] ?? null);

        if ($sesionId !== null) {
            $sesion = CajaSesion::query()->whereKey($sesionId)->firstOrFail();
            $this->autorizar->sesionParaSalida($sesion, $actor);

            return ['sesion' => $sesion, 'fondo' => null];
        }

        $fondo = CajaChicaFondo::query()->whereKey($fondoId)->firstOrFail();
        $this->autorizar->fondoParaOperar($fondo, $actor);

        return ['sesion' => null, 'fondo' => $fondo];
    }

    private function comprobante(mixed $valor): ?TipoComprobanteCajaChica
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        return TipoComprobanteCajaChica::tryFrom($valor);
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

    private function texto(mixed $valor, string $campo, string $mensaje): string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        if ($texto === '') {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        return $texto;
    }

    private function textoOpcional(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        return $texto === '' ? null : $texto;
    }
}
