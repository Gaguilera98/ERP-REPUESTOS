<?php

namespace App\Ventas;

class NombreEnPantalla
{
    public static function titulo(mixed $nombre): string
    {
        $texto = trim((string) $nombre);

        if ($texto === '') {
            return '—';
        }

        $texto = mb_strtolower($texto, 'UTF-8');

        return preg_replace_callback(
            '/(^|[\s\/\-\(\[])(\p{L})/u',
            fn (array $partes): string => $partes[1].mb_strtoupper($partes[2], 'UTF-8'),
            $texto,
        ) ?? $texto;
    }
}
