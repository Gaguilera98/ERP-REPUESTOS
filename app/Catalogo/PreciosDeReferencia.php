<?php

namespace App\Catalogo;

use App\Compras\Montos;
use App\Enums\ModoMoneda;
use App\Models\NegocioConfiguracion;
use App\Models\TipoCambio;
use Illuminate\Validation\ValidationException;

class PreciosDeReferencia
{
    public function habilitado(): bool
    {
        return NegocioConfiguracion::query()->first()?->modo_moneda === ModoMoneda::ConDolar;
    }

    public function vigente(string $moneda): ?TipoCambio
    {
        return TipoCambio::query()->where('moneda_local', $moneda)->latest('id')->first();
    }

    public function validarCotizacion(mixed $valor, string $campo): string
    {
        $validador = validator(['cotizacion' => $valor], [
            'cotizacion' => ['required', 'regex:/^\d{1,6}(\.\d{1,4})?$/', 'numeric', 'min:0.0001'],
        ], [
            'cotizacion.required' => 'Ingresá el tipo de cambio.',
            'cotizacion.regex' => 'Ingresá una cotización con hasta seis enteros y cuatro decimales.',
            'cotizacion.min' => 'El tipo de cambio debe ser positivo.',
        ]);

        if ($validador->fails()) {
            throw ValidationException::withMessages([$campo => $validador->errors()->first('cotizacion')]);
        }

        return Montos::cuatro((string) $valor);
    }

    /**
     * @return array{precio_venta: string|null, precio_mayor: string|null}
     */
    public function convertir(?string $menor, ?string $mayor, ?string $menorUsd, ?string $mayorUsd, string $cotizacion): array
    {
        return [
            'precio_venta' => $menorUsd === null ? $menor : Montos::multiplicar($menorUsd, $cotizacion, 2),
            'precio_mayor' => $mayorUsd === null ? $mayor : Montos::multiplicar($mayorUsd, $cotizacion, 2),
        ];
    }

    public function problema(?string $menor, ?string $mayor): ?string
    {
        if ($menor === null) {
            return 'Ingresá el precio Menor.';
        }

        if (Montos::comparar($menor, '99999999.99', 2) > 0 || ($mayor !== null && Montos::comparar($mayor, '99999999.99', 2) > 0)) {
            return 'El importe convertido supera el máximo admitido.';
        }

        if ($mayor !== null && Montos::comparar($mayor, $menor, 2) > 0) {
            return 'El precio Mayor supera al Menor.';
        }

        return null;
    }

    public function referencia(mixed $valor, string $campo): ?string
    {
        if (blank($valor)) {
            return null;
        }

        if (! is_scalar($valor) || ! preg_match('/^\d{1,8}(\.\d{1,2})?$/', (string) $valor) || Montos::comparar((string) $valor, '0', 2) <= 0) {
            throw ValidationException::withMessages([$campo => 'Ingresá una referencia en USD mayor a cero con hasta dos decimales.']);
        }

        return Montos::dos((string) $valor);
    }
}
