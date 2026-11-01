<?php

namespace App\Clientes;

use App\Enums\TipoAtributoCategoria;
use App\Models\ClienteDocumento;
use App\Models\TipoDocumentoAtributo;
use App\Models\TipoDocumentoIdentidad;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class NormalizarValoresDocumento
{
    /**
     * @return array{valores: array<string, int|float|string>, identidad: string}
     */
    public function para(ClienteDocumento $documento): array
    {
        $tipo = TipoDocumentoIdentidad::query()->with('atributos')->find($documento->tipo_documento_identidad_id);

        if (! $tipo instanceof TipoDocumentoIdentidad) {
            throw ValidationException::withMessages([
                'tipo_documento_identidad_id' => 'Elegí un tipo de documento.',
            ]);
        }

        /** @var Collection<string, TipoDocumentoAtributo> $definiciones */
        $definiciones = $tipo->atributos->keyBy('clave');
        $entrada = is_array($documento->valores_json) ? $documento->valores_json : [];
        $salida = [];
        $errores = [];

        foreach ($definiciones as $clave => $definicion) {
            $valor = $entrada[$clave] ?? null;

            if (is_string($valor)) {
                $valor = trim($valor);
            }

            if ($valor === null || $valor === '') {
                if ($definicion->obligatorio) {
                    $errores['valores_json.'.$clave] = $definicion->etiqueta.' es obligatorio.';
                }

                continue;
            }

            $normalizado = $this->valor($definicion, $valor);

            if ($normalizado === null) {
                $errores['valores_json.'.$clave] = $this->mensajeInvalido($definicion);

                continue;
            }

            $salida[$clave] = $normalizado;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [
            'valores' => $salida,
            'identidad' => $this->identidad($definiciones, $salida),
        ];
    }

    /**
     * @param  Collection<string, TipoDocumentoAtributo>  $definiciones
     * @param  array<string, int|float|string>  $valores
     */
    private function identidad(Collection $definiciones, array $valores): string
    {
        $identificadores = $definiciones
            ->filter(fn (TipoDocumentoAtributo $atributo): bool => $atributo->identifica)
            ->sortBy(['orden', 'id']);

        if ($identificadores->isEmpty()) {
            $identificadores = $definiciones->sortBy(['orden', 'id']);
        }

        $partes = [];

        foreach ($identificadores as $atributo) {
            $partes[] = mb_strtolower(trim((string) ($valores[$atributo->clave] ?? '')));
        }

        return implode('|', $partes);
    }

    private function valor(TipoDocumentoAtributo $definicion, mixed $valor): int|float|string|null
    {
        return match ($definicion->tipo) {
            TipoAtributoCategoria::Decimal => $this->decimal($valor),
            TipoAtributoCategoria::Texto => is_scalar($valor) ? trim((string) $valor) : null,
            TipoAtributoCategoria::Lista => $this->lista($definicion, $valor),
        };
    }

    private function decimal(mixed $valor): int|float|null
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

    private function lista(TipoDocumentoAtributo $definicion, mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);
        $opciones = array_map(strval(...), $definicion->opciones ?? []);

        return in_array($texto, $opciones, true) ? $texto : null;
    }

    private function mensajeInvalido(TipoDocumentoAtributo $definicion): string
    {
        return match ($definicion->tipo) {
            TipoAtributoCategoria::Decimal => $definicion->etiqueta.' tiene que ser un número.',
            TipoAtributoCategoria::Lista => $definicion->etiqueta.' tiene que ser una opción de la lista.',
            TipoAtributoCategoria::Texto => $definicion->etiqueta.' tiene que ser texto.',
        };
    }
}
