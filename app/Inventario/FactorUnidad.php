<?php

namespace App\Inventario;

use App\Compras\Montos;
use App\Enums\UsoUnidad;
use App\Models\ProductoMaestro;
use App\Models\ProductoUnidad;
use App\Models\UnidadMedida;
use Illuminate\Validation\ValidationException;

class FactorUnidad
{
    /**
     * Cuántas unidades base hay en una unidad anotada.
     */
    public function haciaBase(ProductoMaestro $producto, int $unidadId): string
    {
        $producto->loadMissing('unidadBase');
        $base = $producto->unidadBase;

        if (! $base instanceof UnidadMedida) {
            throw ValidationException::withMessages([
                'unidad_medida_id' => 'El producto no tiene unidad base.',
            ]);
        }

        if ($unidadId === (int) $base->id) {
            return '1.000000';
        }

        $empaque = ProductoUnidad::query()
            ->where('producto_maestro_id', $producto->id)
            ->where('unidad_medida_id', $unidadId)
            ->first();

        if ($empaque instanceof ProductoUnidad) {
            return Montos::redondear((string) $empaque->factor_a_base, 6);
        }

        $unidad = UnidadMedida::query()->find($unidadId);

        if (
            $unidad instanceof UnidadMedida
            && $unidad->uso === UsoUnidad::Cantidad
            && $base->uso === UsoUnidad::Cantidad
            && $unidad->magnitud === $base->magnitud
        ) {
            $factor = Montos::dividir($unidad->factor_a_referencia, $base->factor_a_referencia, 6);

            if (Montos::comparar($factor, '1', 6) !== 0) {
                return $factor;
            }
        }

        throw ValidationException::withMessages([
            'unidad_medida_id' => 'Esa unidad no corresponde a este producto. Si es un empaque, definí cuántas unidades base entran.',
        ]);
    }
}
