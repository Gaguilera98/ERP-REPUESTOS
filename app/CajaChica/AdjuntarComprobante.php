<?php

namespace App\CajaChica;

use App\Enums\EstadoCajaChicaMovimiento;
use App\Enums\TipoComprobanteCajaChica;
use App\Models\CajaChicaMovimiento;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjuntarComprobante
{
    public function __construct(private AutorizarOrigen $autorizar) {}

    /**
     * @param  array{tipo_comprobante?: string|null, numero_comprobante?: string|null, comprobante_archivo_path?: string|null}  $data
     */
    public function adjuntar(CajaChicaMovimiento $movimiento, array $data, TenantUser $actor): CajaChicaMovimiento
    {
        return DB::transaction(function () use ($movimiento, $data, $actor): CajaChicaMovimiento {
            $movimiento = CajaChicaMovimiento::query()->whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            $this->autorizar->exigirAccesoARendicion($movimiento, $actor, 'movimiento_id');

            return $this->guardar($movimiento, $data);
        });
    }

    /** @param array{tipo_comprobante?: string|null, numero_comprobante?: string|null, comprobante_archivo_path?: string|null} $data */
    private function guardar(CajaChicaMovimiento $movimiento, array $data): CajaChicaMovimiento
    {
        if ($movimiento->estado !== EstadoCajaChicaMovimiento::PendienteComprobante) {
            throw ValidationException::withMessages([
                'comprobante' => 'Ese gasto no está pendiente de comprobante.',
            ]);
        }

        $ruta = is_string($data['comprobante_archivo_path'] ?? null) ? trim($data['comprobante_archivo_path']) : '';
        $numero = is_string($data['numero_comprobante'] ?? null) ? trim($data['numero_comprobante']) : '';

        if ($ruta === '' && $numero === '') {
            throw ValidationException::withMessages([
                'comprobante' => 'Cargá el número o el archivo del comprobante.',
            ]);
        }

        $movimiento->fill([
            'tipo_comprobante' => TipoComprobanteCajaChica::tryFrom((string) ($data['tipo_comprobante'] ?? '')) ?? TipoComprobanteCajaChica::ReciboInterno,
            'numero_comprobante' => $numero !== '' ? $numero : null,
            'comprobante_archivo_path' => $ruta !== '' ? $ruta : null,
            'estado' => EstadoCajaChicaMovimiento::RendidoCompleto,
        ]);
        $movimiento->save();

        return $movimiento->refresh();
    }
}
