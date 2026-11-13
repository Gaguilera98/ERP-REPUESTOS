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

    /**
     * Reparte la diferencia de una compra vieja solo sobre el stock que queda hoy.
     */
    public function revaluar(string $costoActual, string $stockActual, string $costoAnterior, string $costoNuevo, string $cantidadLinea): string
    {
        if (Montos::comparar($stockActual, '0') !== 1) {
            throw new InvalidArgumentException('No queda stock para corregir.');
        }

        $afectada = Montos::comparar($cantidadLinea, $stockActual, 4) === 1
            ? Montos::cuatro($stockActual)
            : Montos::cuatro($cantidadLinea);
        $diferencia = bcmul(
            bcsub(Montos::cuatro($costoNuevo), Montos::cuatro($costoAnterior), 8),
            $afectada,
            8,
        );
        $valor = bcadd(bcmul(Montos::cuatro($costoActual), Montos::cuatro($stockActual), 8), $diferencia, 8);
        $promedio = Montos::cuatro(bcdiv($valor, Montos::cuatro($stockActual), 8));

        if (Montos::comparar($promedio, '0', 4) === -1) {
            throw new InvalidArgumentException('El costo promedio no puede quedar negativo.');
        }

        return $promedio;
    }
}
