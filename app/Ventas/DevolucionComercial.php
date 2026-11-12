<?php

namespace App\Ventas;

use App\Enums\DevolucionComercialProducto;
use App\Models\ProductoVariante;

class DevolucionComercial
{
    /**
     * @return array{sin_devolucion_comercial: bool, motivo_sin_devolucion: string|null}
     */
    public function de(ProductoVariante $variante): array
    {
        $variante->loadMissing('productoMaestro.categoria');
        $producto = $variante->productoMaestro;
        $marca = $producto?->devolucion_comercial ?? DevolucionComercialProducto::Hereda;

        if ($marca === DevolucionComercialProducto::Bloquea) {
            return [
                'sin_devolucion_comercial' => true,
                'motivo_sin_devolucion' => (string) ($producto->nombre ?? $variante->sku),
            ];
        }

        if ($marca === DevolucionComercialProducto::Permite) {
            return [
                'sin_devolucion_comercial' => false,
                'motivo_sin_devolucion' => null,
            ];
        }

        if ($producto?->categoria?->sin_devolucion_comercial) {
            return [
                'sin_devolucion_comercial' => true,
                'motivo_sin_devolucion' => (string) $producto->categoria->nombre,
            ];
        }

        return [
            'sin_devolucion_comercial' => false,
            'motivo_sin_devolucion' => null,
        ];
    }
}
