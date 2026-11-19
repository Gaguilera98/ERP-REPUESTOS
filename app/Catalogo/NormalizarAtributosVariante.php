<?php

namespace App\Catalogo;

use App\Enums\TipoAtributoCategoria;
use App\Models\CategoriaAtributo;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use Illuminate\Validation\ValidationException;

class NormalizarAtributosVariante
{
    /**
     * @return array<string, int|float|string|list<string>>
     */
    public function para(ProductoVariante $variante): array
    {
        $maestro = ProductoMaestro::query()->find($variante->producto_maestro_id);

        if (! $maestro) {
            return [];
        }

        return $this->paraCategoria($maestro->categoria_id, is_array($variante->atributos_json) ? $variante->atributos_json : []);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, int|float|string|list<string>>
     */
    public function paraCategoria(int $categoriaId, array $entrada): array
    {
        $definiciones = CategoriaAtributo::query()
            ->where('categoria_id', $categoriaId)
            ->orderBy('orden')
            ->orderBy('id')
            ->get()
            ->keyBy('clave');

        $salida = [];
        $errores = [];

        foreach ($definiciones as $clave => $definicion) {
            $valor = $entrada[$clave] ?? null;

            if (is_string($valor)) {
                $valor = trim($valor);
            }

            if (is_array($valor)) {
                $valor = array_values(array_filter(
                    $valor,
                    fn (mixed $item): bool => filled(is_string($item) ? trim($item) : $item),
                ));
            }

            if ($valor === null || $valor === '' || $valor === []) {
                if ($definicion->obligatorio) {
                    $errores['atributos_json.'.$clave] = $definicion->etiqueta.' es obligatorio.';
                }

                continue;
            }

            if ($definicion->tipo === TipoAtributoCategoria::Decimal && $this->esNegativo($valor)) {
                $errores['atributos_json.'.$clave] = $definicion->etiqueta.' no puede ser negativo.';

                continue;
            }

            $normalizado = $this->valor($definicion, $valor);

            if ($normalizado === null) {
                $errores['atributos_json.'.$clave] = $this->mensajeInvalido($definicion);

                continue;
            }

            $salida[$clave] = $normalizado;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $salida;
    }

    /**
     * @return int|float|string|list<string>|null
     */
    private function valor(CategoriaAtributo $definicion, mixed $valor): int|float|string|array|null
    {
        return match ($definicion->tipo) {
            TipoAtributoCategoria::Decimal => $this->decimal($valor),
            TipoAtributoCategoria::Texto => is_scalar($valor) ? trim((string) $valor) : null,
            TipoAtributoCategoria::Lista => $this->lista($definicion, $valor),
        };
    }

    private function decimal(mixed $valor): int|float|null
    {
        $numero = $this->numero($valor);

        if ($numero === null || $numero < 0) {
            return null;
        }

        return $numero;
    }

    private function esNegativo(mixed $valor): bool
    {
        $numero = $this->numero($valor);

        return $numero !== null && $numero < 0;
    }

    private function numero(mixed $valor): int|float|null
    {
        if (is_bool($valor)) {
            return null;
        }

        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if (! is_int($valor) && ! is_float($valor) && ! (is_string($valor) && is_numeric($valor))) {
            return null;
        }

        $texto = is_string($valor) ? $valor : (string) $valor;

        if (preg_match('/^-?\d+$/', $texto) === 1) {
            return (int) $texto;
        }

        return (float) $texto;
    }

    /**
     * Un eje de variante guarda un solo texto. En la ficha, la lista guarda uno o varios.
     *
     * @return string|list<string>|null
     */
    private function lista(CategoriaAtributo $definicion, mixed $valor): string|array|null
    {
        $opciones = array_map(strval(...), $definicion->opciones ?? []);

        if (is_string($valor)) {
            return in_array($valor, $opciones, true) ? $valor : null;
        }

        if (! is_array($valor)) {
            return null;
        }

        $elegidos = [];

        foreach ($valor as $item) {
            if (! is_scalar($item)) {
                return null;
            }

            $texto = trim((string) $item);

            if (! in_array($texto, $opciones, true)) {
                return null;
            }

            $elegidos[] = $texto;
        }

        $elegidos = array_values(array_unique($elegidos));
        $orden = array_flip($opciones);

        usort(
            $elegidos,
            fn (string $primero, string $segundo): int => ($orden[$primero] ?? 0) <=> ($orden[$segundo] ?? 0),
        );

        return $elegidos;
    }

    private function mensajeInvalido(CategoriaAtributo $definicion): string
    {
        return match ($definicion->tipo) {
            TipoAtributoCategoria::Decimal => $definicion->etiqueta.' tiene que ser un número.',
            TipoAtributoCategoria::Lista => $definicion->etiqueta.' tiene que ser una opción de la lista.',
            TipoAtributoCategoria::Texto => $definicion->etiqueta.' tiene que ser texto.',
        };
    }
}
