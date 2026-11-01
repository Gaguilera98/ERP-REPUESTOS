<?php

namespace App\Compras;

use InvalidArgumentException;

class CostoPromedio
{
    /**
     * Promedio ponderado con el stock físico de la variante, en la unidad base.
     * Si no queda stock, el costo de esta entrada pasa a ser el promedio.
     */
    public function resultante(string $costoActual, string $stockActual, string $costoEntrada, string $cantidad): string
    {
        if (Montos::comparar($cantidad, '0') !== 1) {
            throw new InvalidArgumentException('La entrada tiene que sumar unidades.');
        }

        if (Montos::comparar($stockActual, '0') === -1) {
            throw new InvalidArgumentException('El stock no puede ser negativo.');
        }

        $costoEntrada = Montos::cuatro($costoEntrada);

        if (Montos::comparar($stockActual, '0') === 0) {
            return $costoEntrada;
        }

        $valorActual = bcmul(Montos::cuatro($costoActual), Montos::cuatro($stockActual), 8);
        $valorEntrada = bcmul($costoEntrada, Montos::cuatro($cantidad), 8);
        $total = bcadd($valorActual, $valorEntrada, 8);
        $stock = bcadd(Montos::cuatro($stockActual), Montos::cuatro($cantidad), 4);

        return Montos::cuatro(bcdiv($total, $stock, 8));
    }
}
