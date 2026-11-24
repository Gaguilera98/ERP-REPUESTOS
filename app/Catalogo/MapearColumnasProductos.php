<?php

namespace App\Catalogo;

use App\Enums\TipoAtributoCategoria;
use App\Models\CategoriaAtributo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MapearColumnasProductos
{
    public const CAMPOS = [
        'sku' => 'SKU / Código', 'nombre' => 'Nombre del producto', 'categoria' => 'Categoría',
        'marca' => 'Marca', 'unidad_stock' => 'Unidad de stock', 'codigo_barras' => 'Código de barras',
        'precio_menor' => 'Precio al por menor', 'precio_mayor' => 'Precio al por mayor',
        'cantidad_inicial' => 'Cantidad inicial', 'costo_unitario' => 'Costo unitario',
        'proveedor' => 'Proveedor', 'atributos' => 'Ficha en JSON (compatibilidad)',
    ];

    /** @return array<string, array{label: string, clave: string, tipo: TipoAtributoCategoria, unidad: ?int, simbolo: ?string, opciones: array, categorias: array<int, string>, reglas: array<int, array{obligatorio: bool, opciones: array}>}> */
    public function atributos(): array
    {
        $campos = [];
        $grupos = CategoriaAtributo::query()->with(['categoria', 'unidadMedida'])
            ->whereHas('categoria', fn ($query) => $query->where('is_active', true))->orderBy('id')->get()
            ->groupBy(fn (CategoriaAtributo $atributo): string => json_encode([
                $atributo->clave, $atributo->tipo->value, $atributo->unidad_medida_id,
            ], JSON_THROW_ON_ERROR));
        foreach ($grupos as $grupo) {
            $atributo = $grupo->first();
            $categorias = $grupo->mapWithKeys(fn (CategoriaAtributo $item): array => [$item->categoria_id => $item->categoria->nombre])->all();
            $campos['ficha_'.$atributo->id] = [
                'label' => $atributo->etiqueta.($atributo->unidadMedida ? ' ['.$atributo->unidadMedida->simbolo.']' : '').' · '.implode(', ', $categorias),
                'clave' => $atributo->clave, 'tipo' => $atributo->tipo, 'unidad' => $atributo->unidad_medida_id, 'simbolo' => $atributo->unidadMedida?->simbolo,
                'opciones' => $grupo->flatMap(fn (CategoriaAtributo $item): array => $item->opciones ?? [])->unique()->values()->all(), 'categorias' => $categorias,
                'reglas' => $grupo->mapWithKeys(fn (CategoriaAtributo $item): array => [$item->categoria_id => ['obligatorio' => $item->obligatorio, 'opciones' => $item->opciones ?? []]])->all(),
            ];
        }

        return $campos;
    }

    /** @return array<string, string> */
    public function opciones(): array
    {
        return [...self::CAMPOS, ...array_map(fn (array $campo): string => $campo['label'], $this->atributos())];
    }

    /** @param list<string> $columnas @return array<int, string> */
    public function sugerir(array $columnas): array
    {
        $atributos = $this->atributos();
        $opciones = $this->opciones();
        $alias = ['codigo' => 'sku', 'producto' => 'nombre', 'nombre' => 'nombre', 'unidad' => 'unidad_stock',
            'stock' => 'cantidad_inicial', 'cantidad' => 'cantidad_inicial', 'costo' => 'costo_unitario',
            'precio' => 'precio_menor', 'precio minorista' => 'precio_menor', 'precio mayorista' => 'precio_mayor'];
        $usados = [];
        $mapa = [];
        foreach ($columnas as $indice => $columna) {
            $normal = $this->normalizar($columna);
            $coincidencias = [];
            foreach ($opciones as $destino => $label) {
                if ($normal === $this->normalizar($destino) || $normal === $this->normalizar($label)
                    || (isset($atributos[$destino]) && $normal === $this->normalizar($atributos[$destino]['clave']))) {
                    $coincidencias[] = $destino;
                }
            }
            $destino = count($coincidencias) === 1 ? $coincidencias[0] : ($alias[$normal] ?? '');
            $mapa[$indice] = isset($usados[$destino]) ? '' : $destino;
            if ($destino !== '') {
                $usados[$destino] = true;
            }
        }

        return $mapa;
    }

    /** @param array{columnas: list<string>, filas: list<array{fila: int, valores: list<string>}>} $archivo @param array<int, string|null> $mapa @return list<array<string, string|int>> */
    public function aplicar(array $archivo, array $mapa): array
    {
        $opciones = $this->opciones();
        $usados = [];
        foreach ($archivo['columnas'] as $indice => $columna) {
            $destino = $mapa[$indice] ?? null;
            if ($destino === 'ignorar') {
                continue;
            }
            if (! is_string($destino) || ! isset($opciones[$destino]) || isset($usados[$destino])) {
                throw ValidationException::withMessages(['columnasData.mapa.'.$indice => 'Elegí un campo sin repetirlo, o indicá que esta columna se omite.']);
            }
            $usados[$destino] = true;
        }
        if (! isset($usados['sku'])) {
            throw ValidationException::withMessages(['columnasData.mapa' => 'Relacioná una columna con el SKU / Código.']);
        }

        return array_map(function (array $origen) use ($archivo, $mapa): array {
            $fila = ['fila' => $origen['fila'], ...array_fill_keys(ArchivoProductos::COLUMNAS, '')];
            $ficha = [];
            foreach ($archivo['columnas'] as $indice => $columna) {
                $destino = $mapa[$indice];
                $valor = $origen['valores'][$indice] ?? '';
                if (str_starts_with($destino, 'ficha_')) {
                    $ficha[$destino] = $valor;
                } elseif ($destino !== 'ignorar') {
                    $fila[$destino] = $valor;
                }
            }
            if ($ficha !== []) {
                $fila['_ficha_columnas'] = json_encode($ficha, JSON_THROW_ON_ERROR);
            }

            return $fila;
        }, $archivo['filas']);
    }

    /** @param array<string, mixed> $fila @param array<string, mixed> $ficha @param array<string, array> $campos @return array<string, mixed> */
    public function ficha(array $fila, int $categoriaId, array $ficha, array $campos, bool $validar = true): array
    {
        $errores = [];
        foreach (json_decode($fila['_ficha_columnas'] ?? '{}', true, flags: JSON_THROW_ON_ERROR) as $destino => $valor) {
            if (blank($valor)) {
                continue;
            }
            $campo = $campos[$destino] ?? null;
            if (! $campo || ! isset($campo['categorias'][$categoriaId])) {
                $errores[] = 'La columna '.($campo['label'] ?? $destino).' tiene un valor que no corresponde a la categoría elegida. Revisá la ficha de esta fila.';

                continue;
            }
            $ficha[$campo['clave']] = match ($campo['tipo']) {
                TipoAtributoCategoria::Decimal => str_replace(',', '.', $valor),
                TipoAtributoCategoria::Lista => array_values(array_filter(array_map('trim', explode('|', $valor)), fn (string $item): bool => $item !== '')),
                default => $valor,
            };
        }
        if ($validar && $errores !== []) {
            throw ValidationException::withMessages(['atributos' => $errores]);
        }

        return $ficha;
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($texto))));
    }
}
