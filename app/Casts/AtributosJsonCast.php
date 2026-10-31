<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<array<string, int|float|string|list<string>>, array<string, int|float|string|list<string>>>
 */
class AtributosJsonCast implements CastsAttributes
{
    /**
     * @return array<string, int|float|string|list<string>>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $valor = is_array($value) ? $value : [];

        return json_encode($valor === [] ? new \stdClass : $valor, JSON_THROW_ON_ERROR);
    }
}
