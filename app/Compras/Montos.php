<?php

namespace App\Compras;

use InvalidArgumentException;

final class Montos
{
    public static function cuatro(string|int|float $valor): string
    {
        return self::redondear(self::normalizar($valor), 4);
    }

    public static function dos(string|int|float $valor): string
    {
        return self::redondear(self::normalizar($valor), 2);
    }

    public static function multiplicar(string|int|float $a, string|int|float $b, int $escala): string
    {
        return self::redondear(bcmul(self::normalizar($a), self::normalizar($b), $escala + 4), $escala);
    }

    public static function sumar(string|int|float $a, string|int|float $b, int $escala): string
    {
        return bcadd(self::normalizar($a), self::normalizar($b), $escala);
    }

    public static function porcentaje(string|int|float $base, string|int|float $porcentaje, int $escala = 2): string
    {
        return self::dividir(self::multiplicar($base, $porcentaje, $escala + 4), '100', $escala);
    }

    public static function dividir(string|int|float $a, string|int|float $b, int $escala): string
    {
        $divisor = self::normalizar($b);

        if (bccomp($divisor, '0', 8) === 0) {
            throw new InvalidArgumentException('No se divide por cero.');
        }

        return self::redondear(bcdiv(self::normalizar($a), $divisor, $escala + 4), $escala);
    }

    public static function comparar(string|int|float $a, string|int|float $b, int $escala = 4): int
    {
        return bccomp(self::normalizar($a), self::normalizar($b), $escala);
    }

    public static function normalizar(string|int|float $valor): string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        if (is_float($valor)) {
            return number_format($valor, 8, '.', '');
        }

        $valor = trim($valor);

        if ($valor === '' || preg_match('/^-?\d+(\.\d+)?$/', $valor) !== 1) {
            throw new InvalidArgumentException('Monto inválido.');
        }

        return $valor;
    }

    public static function redondear(string $numero, int $decimales): string
    {
        $numero = self::normalizar($numero);
        $negativo = str_starts_with($numero, '-');

        if ($negativo) {
            $numero = substr($numero, 1);
        }

        $factor = bcpow('10', (string) $decimales, 0);
        $escalado = bcmul($numero, $factor, 8);
        $redondeado = bcadd($escalado, '0.5', 0);
        $resultado = bcdiv($redondeado, $factor, $decimales);

        return $negativo ? '-'.$resultado : $resultado;
    }
}
