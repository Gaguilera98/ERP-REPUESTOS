<?php

namespace App\Ventas;

use App\Compras\Montos;
use App\Enums\TipoPrecioCliente;
use App\Models\ProductoVariante;

class PrecioDeLista
{
    public function de(ProductoVariante $variante, TipoPrecioCliente $lista): string
    {
        if ($lista === TipoPrecioCliente::Mayor && $this->tieneMayor($variante)) {
            return Montos::dos((string) $variante->precio_mayor);
        }

        return Montos::dos((string) $variante->precio_venta);
    }

    public function nota(ProductoVariante $variante, TipoPrecioCliente $lista): string
    {
        $venta = Montos::dos((string) $variante->precio_venta);

        if ($lista === TipoPrecioCliente::Mayor && ! $this->tieneMayor($variante)) {
            return 'sin precio mayor';
        }

        if (! $this->tieneMayor($variante)) {
            return '';
        }

        $mayor = Montos::dos((string) $variante->precio_mayor);

        if ($mayor === $venta) {
            return '';
        }

        if ($lista === TipoPrecioCliente::Mayor) {
            return 'menor '.$venta;
        }

        return 'mayor '.$mayor;
    }

    private function tieneMayor(ProductoVariante $variante): bool
    {
        return $variante->precio_mayor !== null
            && Montos::comparar((string) $variante->precio_mayor, '0', 2) === 1;
    }
}
