<?php

namespace App\Compras;

final class MontoEnPalabras
{
    public static function numerosYLetras(string|int|float $monto, string $moneda): string
    {
        $numero = self::numero($monto);
        $negativo = str_starts_with($numero, '-');

        if ($negativo) {
            $numero = substr($numero, 1);
        }

        $visible = ($negativo ? '-' : '').strtoupper($moneda).' '.$numero;

        return $visible.' ('.self::decir($monto, $moneda).')';
    }

    public static function decir(string|int|float $monto, string $moneda): string
    {
        $texto = Montos::cuatro($monto);
        $negativo = str_starts_with($texto, '-');

        if ($negativo) {
            $texto = substr($texto, 1);
        }

        [$enteroTexto, $fraccion] = array_pad(explode('.', $texto, 2), 2, '0000');
        $entero = (int) $enteroTexto;
        $fraccion = str_pad(substr($fraccion, 0, 4), 4, '0');
        $centavos = (int) substr($fraccion, 0, 2);
        $resto = (int) substr($fraccion, 2, 2);

        $trozos = [];

        if ($entero > 0 || ($centavos === 0 && $resto === 0)) {
            $trozos[] = self::conMoneda($entero, $moneda);
        }

        if ($centavos > 0) {
            $trozos[] = self::conUnidad($centavos, 'centavo', 'centavos');
        }

        if ($resto > 0) {
            $trozos[] = self::conUnidad($resto, 'diezmilésimo', 'diezmilésimos');
        }

        $dicho = $trozos[0];

        if (isset($trozos[1])) {
            $dicho .= ($entero > 0 ? ' con ' : ' y ').$trozos[1];
        }

        if (isset($trozos[2])) {
            $dicho .= ' y '.$trozos[2];
        }

        return $negativo ? 'menos '.$dicho : $dicho;
    }

    private static function numero(string|int|float $monto): string
    {
        $texto = Montos::cuatro($monto);
        $negativo = str_starts_with($texto, '-');

        if ($negativo) {
            $texto = substr($texto, 1);
        }

        [$entero, $fraccion] = explode('.', $texto);
        $fraccion = str_pad(substr($fraccion, 0, 4), 4, '0');

        if (str_ends_with($fraccion, '00')) {
            $fraccion = substr($fraccion, 0, 2);
        }

        $numero = $fraccion === '00' ? $entero : $entero.','.$fraccion;

        return $negativo ? '-'.$numero : $numero;
    }

    private static function conMoneda(int $cantidad, string $moneda): string
    {
        [$singular, $plural] = match (strtoupper($moneda)) {
            'BOB' => ['boliviano', 'bolivianos'],
            'PEN' => ['sol', 'soles'],
            'BRL' => ['real', 'reales'],
            default => [$moneda, $moneda],
        };

        if ($cantidad === 0) {
            return 'cero '.$plural;
        }

        return self::conUnidad($cantidad, $singular, $plural);
    }

    private static function conUnidad(int $cantidad, string $singular, string $plural): string
    {
        $nombre = $cantidad === 1 ? $singular : $plural;

        return self::cardinal($cantidad, true).' '.$nombre;
    }

    private static function cardinal(int $numero, bool $apocope): string
    {
        if ($numero < 30) {
            return self::hastaVeintinueve($numero, $apocope);
        }

        if ($numero < 100) {
            $decenas = [
                3 => 'treinta',
                4 => 'cuarenta',
                5 => 'cincuenta',
                6 => 'sesenta',
                7 => 'setenta',
                8 => 'ochenta',
                9 => 'noventa',
            ];
            $decena = intdiv($numero, 10);
            $unidad = $numero % 10;

            if ($unidad === 0) {
                return $decenas[$decena];
            }

            return $decenas[$decena].' y '.self::hastaVeintinueve($unidad, $apocope);
        }

        if ($numero < 1000) {
            if ($numero === 100) {
                return 'cien';
            }

            $centenas = [
                1 => 'ciento',
                2 => 'doscientos',
                3 => 'trescientos',
                4 => 'cuatrocientos',
                5 => 'quinientos',
                6 => 'seiscientos',
                7 => 'setecientos',
                8 => 'ochocientos',
                9 => 'novecientos',
            ];
            $centena = intdiv($numero, 100);
            $resto = $numero % 100;

            if ($resto === 0) {
                return $centenas[$centena];
            }

            return $centenas[$centena].' '.self::cardinal($resto, $apocope);
        }

        if ($numero < 1_000_000) {
            $miles = intdiv($numero, 1000);
            $resto = $numero % 1000;
            $milesTexto = $miles === 1 ? 'mil' : self::cardinal($miles, true).' mil';

            if ($resto === 0) {
                return $milesTexto;
            }

            return $milesTexto.' '.self::cardinal($resto, $apocope);
        }

        $millones = intdiv($numero, 1_000_000);
        $resto = $numero % 1_000_000;
        $millonesTexto = $millones === 1 ? 'un millón' : self::cardinal($millones, true).' millones';

        if ($resto === 0) {
            return $millonesTexto;
        }

        return $millonesTexto.' '.self::cardinal($resto, $apocope);
    }

    private static function hastaVeintinueve(int $numero, bool $apocope): string
    {
        return match ($numero) {
            1 => $apocope ? 'un' : 'uno',
            2 => 'dos',
            3 => 'tres',
            4 => 'cuatro',
            5 => 'cinco',
            6 => 'seis',
            7 => 'siete',
            8 => 'ocho',
            9 => 'nueve',
            10 => 'diez',
            11 => 'once',
            12 => 'doce',
            13 => 'trece',
            14 => 'catorce',
            15 => 'quince',
            16 => 'dieciséis',
            17 => 'diecisiete',
            18 => 'dieciocho',
            19 => 'diecinueve',
            20 => 'veinte',
            21 => $apocope ? 'veintiún' : 'veintiuno',
            22 => 'veintidós',
            23 => 'veintitrés',
            24 => 'veinticuatro',
            25 => 'veinticinco',
            26 => 'veintiséis',
            27 => 'veintisiete',
            28 => 'veintiocho',
            29 => 'veintinueve',
            default => 'cero',
        };
    }
}
