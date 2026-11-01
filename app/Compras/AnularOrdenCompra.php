<?php

namespace App\Compras;

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnularOrdenCompra
{
    public function anular(OrdenCompra $orden): OrdenCompra
    {
        return DB::transaction(function () use ($orden): OrdenCompra {
            $orden = OrdenCompra::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if ($orden->estado !== EstadoOrdenCompra::Pendiente || $orden->detalles()->where('cantidad_recibida', '>', 0)->exists()) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se anula una compra pendiente que no recibió mercadería.',
                ]);
            }

            $orden->estado = EstadoOrdenCompra::Anulada;
            $orden->save();

            return $orden->refresh();
        });
    }
}
