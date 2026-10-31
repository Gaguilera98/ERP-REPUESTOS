<?php

namespace App\Catalogo;

use App\Models\Almacen;
use App\Models\InventarioStock;
use App\Models\ProductoVariante;

class AsegurarStockEnCero
{
    public function paraVariante(ProductoVariante $variante): void
    {
        Almacen::query()->orderBy('id')->each(function (Almacen $almacen) use ($variante): void {
            $this->fila($variante, $almacen);
        });
    }

    public function paraAlmacen(Almacen $almacen): void
    {
        ProductoVariante::query()->orderBy('id')->each(function (ProductoVariante $variante) use ($almacen): void {
            $this->fila($variante, $almacen);
        });
    }

    private function fila(ProductoVariante $variante, Almacen $almacen): void
    {
        $existe = InventarioStock::query()
            ->where('producto_variante_id', $variante->id)
            ->where('almacen_id', $almacen->id)
            ->exists();

        if ($existe) {
            return;
        }

        InventarioStock::query()->create([
            'producto_variante_id' => $variante->id,
            'almacen_id' => $almacen->id,
        ]);
    }
}
