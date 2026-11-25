<?php

namespace App\Catalogo;

use App\Enums\MagnitudUnidad;
use App\Enums\TipoAtributoCategoria;
use App\Enums\UsoUnidad;
use App\Models\Categoria;
use App\Models\NegocioConfiguracion;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportarConfiguracionCatalogo
{
    /** @param array<string, array> $archivo @param array<string, int|string|null> $asignaciones @return array<string, mixed> */
    public function revisar(array $archivo, array $asignaciones, TenantUser $usuario): array
    {
        $this->autorizar($usuario);
        $datos = $this->validarArchivo($archivo);
        $existentes = UnidadMedida::query()->orderBy('id')->get();
        $categorias = Categoria::query()->with('atributos')->orderBy('id')->get();
        $revision = ['unidades' => [], 'categorias' => [], 'atributos' => [], 'errores' => []];
        foreach ($datos['Unidades'] as $fila) {
            $codigo = $fila['codigo'];
            $unidad = null;
            $problema = null;
            if (filled($asignaciones[$codigo] ?? null)) {
                $unidad = $existentes->firstWhere('id', (int) $asignaciones[$codigo]);
                if (! $unidad || ! $this->unidadCompatible($fila['datos'], $unidad)) {
                    $problema = 'La unidad elegida no existe en este negocio o su uso, magnitud o equivalencia no coinciden.';
                }
            } else {
                $coincidencias = $existentes->filter(fn (UnidadMedida $unidad): bool => mb_strtolower($unidad->nombre) === mb_strtolower($fila['datos']['nombre']) || $unidad->simbolo === $fila['datos']['simbolo']);
                if ($coincidencias->count() === 1
                    && mb_strtolower($coincidencias->first()->nombre) === mb_strtolower($fila['datos']['nombre'])
                    && $coincidencias->first()->simbolo === $fila['datos']['simbolo']
                    && $this->unidadCompatible($fila['datos'], $coincidencias->first())) {
                    $unidad = $coincidencias->first();
                } elseif ($coincidencias->isNotEmpty()) {
                    $problema = 'Hay una unidad con ese nombre o símbolo y otra definición. Elegí una existente compatible o corregí el Excel.';
                }
            }
            if (! $unidad && ! $problema && Gate::forUser($usuario)->denies('create', UnidadMedida::class)) {
                $problema = 'No tenés permiso para crear unidades. Relacioná una existente compatible o pedí autorización.';
            }
            $revision['unidades'][$codigo] = [...$fila, 'id' => $unidad?->id, 'accion' => $problema ? 'conflicto' : ($unidad ? 'reutilizar' : 'crear'), 'destino' => $unidad ? $unidad->nombre.' ('.$unidad->simbolo.')' : $fila['datos']['nombre']];
            if ($problema) {
                $revision['errores'][] = 'Unidades, fila '.$fila['_fila'].' ('.$codigo.'): '.$problema;
            }
        }
        foreach ($datos['Categorias'] as $fila) {
            $coincidencias = $categorias->filter(fn (Categoria $categoria): bool => mb_strtolower($categoria->nombre) === mb_strtolower($fila['nombre']));
            $categoria = $coincidencias->count() === 1 ? $coincidencias->first() : null;
            $problema = $coincidencias->count() > 1 || ($categoria && ! $categoria->is_active);
            $revision['categorias'][$fila['codigo']] = [...$fila, 'id' => $categoria?->id, 'accion' => $problema ? 'conflicto' : ($categoria ? 'reutilizar' : 'crear')];
            if ($problema) {
                $revision['errores'][] = 'Categorias, fila '.$fila['_fila'].': la categoría es ambigua o está inactiva. Corregila desde Categorías.';
            }
        }
        foreach ($datos['Atributos'] as $fila) {
            $categoria = $revision['categorias'][$fila['categoria']];
            $unidad = $fila['unidad'] === '' ? null : $revision['unidades'][$fila['unidad']];
            $modelo = $categoria['id'] ? $categorias->firstWhere('id', $categoria['id']) : null;
            $existente = $modelo?->atributos->firstWhere('clave', $fila['datos']['clave']);
            $datosAtributo = [...$fila['datos'], 'unidad_medida_id' => $unidad['id'] ?? null];
            $problema = null;
            if ($categoria['accion'] === 'conflicto' || ($unidad && $unidad['accion'] === 'conflicto')) {
                $problema = 'Resolvé primero la categoría o unidad referenciada.';
            } elseif ($existente) {
                foreach ($datosAtributo as $campo => $valor) {
                    $actual = $campo === 'tipo' ? $existente->tipo->value : $existente->getAttribute($campo);
                    if ($actual !== $valor || ($campo === 'unidad_medida_id' && $unidad && $unidad['id'] === null)) {
                        $problema = 'La clave ya existe con otra definición. No se sobrescribe su etiqueta, tipo, unidad, obligatoriedad ni opciones.';
                    }
                }
            } elseif ($modelo && Gate::forUser($usuario)->denies('update', $modelo)) {
                $problema = 'No tenés permiso para agregar atributos a esta categoría existente.';
            }
            $revision['atributos'][] = [...$fila, 'id' => $existente?->id, 'accion' => $problema ? 'conflicto' : ($existente ? 'reutilizar' : 'crear')];
            if ($problema) {
                $revision['errores'][] = 'Atributos, fila '.$fila['_fila'].' ('.$fila['datos']['clave'].'): '.$problema;
            }
        }
        $revision['version'] = hash('sha256', json_encode([tenant()->getTenantKey(), $revision], JSON_THROW_ON_ERROR));

        return $revision;
    }

    /** @param array<string, array> $archivo @param array<string, int|string|null> $asignaciones @return array<string, int> */
    public function confirmar(array $archivo, array $asignaciones, string $version, TenantUser $usuario): array
    {
        $this->autorizar($usuario);

        return DB::transaction(function () use ($archivo, $asignaciones, $version, $usuario): array {
            NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();
            $revision = $this->revisar($archivo, $asignaciones, $usuario);
            if ($revision['errores'] !== []) {
                throw ValidationException::withMessages(['archivo' => $revision['errores']]);
            }
            if (! hash_equals($version, $revision['version'])) {
                throw ValidationException::withMessages(['archivo' => 'El catálogo cambió desde la revisión. Actualizá la revisión antes de confirmar.']);
            }
            $resultado = ['unidades' => 0, 'categorias' => 0, 'atributos' => 0];
            $unidades = [];
            foreach ($revision['unidades'] as $codigo => $fila) {
                if ($fila['accion'] === 'crear') {
                    Gate::forUser($usuario)->authorize('create', UnidadMedida::class);
                    $unidades[$codigo] = UnidadMedida::query()->create($fila['datos'])->id;
                    $resultado['unidades']++;
                } else {
                    $unidades[$codigo] = $fila['id'];
                }
            }
            $categorias = [];
            foreach ($revision['categorias'] as $codigo => $fila) {
                $categorias[$codigo] = $fila['id'] ? Categoria::query()->findOrFail($fila['id']) : Categoria::query()->create(['nombre' => $fila['nombre']]);
                $resultado['categorias'] += $fila['accion'] === 'crear' ? 1 : 0;
            }
            $ordenes = [];
            foreach ($revision['atributos'] as $fila) {
                if ($fila['accion'] !== 'crear') {
                    continue;
                }
                $categoria = $categorias[$fila['categoria']];
                if ($revision['categorias'][$fila['categoria']]['accion'] === 'reutilizar') {
                    Gate::forUser($usuario)->authorize('update', $categoria);
                }
                $ordenes[$categoria->id] ??= (int) $categoria->atributos()->max('orden');
                $categoria->atributos()->create([...$fila['datos'], 'unidad_medida_id' => $fila['unidad'] === '' ? null : $unidades[$fila['unidad']], 'orden' => ++$ordenes[$categoria->id]]);
                $resultado['atributos']++;
            }

            return $resultado;
        });
    }

    private function autorizar(TenantUser $usuario): void
    {
        abort_unless((string) $usuario->tenant_id === (string) tenant()->getTenantKey(), 403);
        Gate::forUser($usuario)->authorize('viewAny', Categoria::class);
        Gate::forUser($usuario)->authorize('create', Categoria::class);
    }

    /** @param array<string, mixed> $datos */
    private function unidadCompatible(array $datos, UnidadMedida $unidad): bool
    {
        return $datos['uso'] === $unidad->uso->value && $datos['magnitud'] === $unidad->magnitud->value
            && ($unidad->uso === UsoUnidad::Atributo || (bccomp($datos['factor_a_referencia'], $unidad->factor_a_referencia, 6) === 0
                && $datos['decimales'] === $unidad->decimales && $datos['es_referencia'] === $unidad->es_referencia));
    }

    /** @param array<string, array> $archivo @return array<string, array> */
    private function validarArchivo(array $archivo): array
    {
        Validator::make($archivo, ['Unidades' => ['present', 'array', 'max:500'], 'Categorias' => ['required', 'array', 'min:1', 'max:500'], 'Atributos' => ['present', 'array', 'max:500']], ['required' => 'Incluí al menos una categoría.', 'max' => 'Cada hoja admite hasta 500 filas.', 'present' => 'Falta una hoja de la plantilla.'])->validate();
        $datos = ['Unidades' => [], 'Categorias' => [], 'Atributos' => []];
        $errores = [];
        $nombres = [];
        $simbolos = [];
        foreach ($archivo['Unidades'] as $fila) {
            $this->validarFila($fila, ['codigo' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,49}$/'], 'nombre' => ['required', 'string', 'max:255'], 'simbolo' => ['required', 'string', 'max:255'], 'uso' => ['required', Rule::enum(UsoUnidad::class)], 'magnitud' => ['required', Rule::enum(MagnitudUnidad::class)]], 'Unidades');
            $codigo = strtoupper($fila['codigo']);
            $cantidad = $fila['uso'] === 'cantidad';
            if ($cantidad) {
                $fila['factor_a_referencia'] = str_replace(',', '.', $fila['factor_a_referencia']);
                $this->validarFila($fila, ['factor_a_referencia' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'gt:0'], 'decimales' => ['required', 'integer', 'between:0,4'], 'es_referencia' => ['required', Rule::in(['si', 'no'])]], 'Unidades');
            } elseif (filled($fila['factor_a_referencia'] ?? '') || filled($fila['decimales'] ?? '') || filled($fila['es_referencia'] ?? '')) {
                $errores[] = 'Unidades, fila '.$fila['_fila'].': las medidas de atributo dejan vacíos factor_a_referencia, decimales y es_referencia.';
            }
            $definicion = ['nombre' => $fila['nombre'], 'simbolo' => $fila['simbolo'], 'uso' => $fila['uso'], 'magnitud' => $fila['magnitud'], 'factor_a_referencia' => $cantidad ? bcadd($fila['factor_a_referencia'], '0', 6) : '1.000000', 'decimales' => $cantidad ? (int) $fila['decimales'] : 0, 'es_referencia' => $cantidad && $fila['es_referencia'] === 'si'];
            if ($definicion['es_referencia'] && bccomp($definicion['factor_a_referencia'], '1', 6) !== 0) {
                $errores[] = 'Unidades, fila '.$fila['_fila'].': una unidad de referencia debe tener factor 1.';
            }
            if (isset($datos['Unidades'][$codigo]) || isset($nombres[mb_strtolower($fila['nombre'])]) || isset($simbolos[$fila['simbolo']])) {
                $errores[] = 'Unidades, fila '.$fila['_fila'].': no repitas código, nombre ni símbolo dentro de la hoja.';
            }
            $datos['Unidades'][$codigo] = ['codigo' => $codigo, '_fila' => $fila['_fila'], 'datos' => $definicion];
            $nombres[mb_strtolower($fila['nombre'])] = true;
            $simbolos[$fila['simbolo']] = true;
        }
        $nombres = [];
        foreach ($archivo['Categorias'] as $fila) {
            $this->validarFila($fila, ['codigo' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,49}$/'], 'nombre' => ['required', 'string', 'max:255']], 'Categorias');
            $codigo = strtoupper($fila['codigo']);
            if (isset($datos['Categorias'][$codigo]) || isset($nombres[mb_strtolower($fila['nombre'])])) {
                $errores[] = 'Categorias, fila '.$fila['_fila'].': no repitas código ni nombre.';
            }
            $datos['Categorias'][$codigo] = ['codigo' => $codigo, 'nombre' => $fila['nombre'], '_fila' => $fila['_fila']];
            $nombres[mb_strtolower($fila['nombre'])] = true;
        }
        $claves = [];
        foreach ($archivo['Atributos'] as $fila) {
            $this->validarFila($fila, ['categoria' => ['required', Rule::in(array_keys($datos['Categorias']))], 'etiqueta' => ['required', 'string', 'max:255'], 'clave' => ['required', 'regex:/^[a-z][a-z0-9_]{0,254}$/'], 'tipo' => ['required', Rule::enum(TipoAtributoCategoria::class)], 'obligatorio' => ['required', Rule::in(['si', 'no'])], 'unidad' => ['nullable', Rule::in(array_keys($datos['Unidades']))], 'opciones' => ['nullable', 'string', 'max:10000']], 'Atributos');
            $opciones = $fila['opciones'] === '' ? [] : array_map('trim', explode('|', $fila['opciones']));
            if (($fila['tipo'] === 'lista' && ($opciones === [] || in_array('', $opciones, true) || count(array_unique(array_map(mb_strtolower(...), $opciones))) !== count($opciones)))
                || ($fila['tipo'] !== 'lista' && $opciones !== []) || count(array_filter($opciones, fn (string $opcion): bool => mb_strlen($opcion) > 255)) > 0) {
                $errores[] = 'Atributos, fila '.$fila['_fila'].': solo lista admite opciones; deben existir y ser únicas, de hasta 255 caracteres.';
            }
            if ($fila['unidad'] !== '') {
                $unidad = $datos['Unidades'][$fila['unidad']]['datos'];
                if ($fila['tipo'] !== 'decimal' || ($unidad['uso'] === 'cantidad' && $unidad['magnitud'] === 'conteo')) {
                    $errores[] = 'Atributos, fila '.$fila['_fila'].': solo decimal admite una unidad de medida; no uses conteo de stock como medida de ficha.';
                }
            }
            $identidad = $fila['categoria'].':'.$fila['clave'];
            if (isset($claves[$identidad])) {
                $errores[] = 'Atributos, fila '.$fila['_fila'].': la clave está repetida en esta categoría.';
            }
            $claves[$identidad] = true;
            $datos['Atributos'][] = ['categoria' => $fila['categoria'], 'unidad' => $fila['unidad'], '_fila' => $fila['_fila'], 'datos' => ['etiqueta' => $fila['etiqueta'], 'clave' => $fila['clave'], 'tipo' => $fila['tipo'], 'obligatorio' => $fila['obligatorio'] === 'si', 'opciones' => $fila['tipo'] === 'lista' ? $opciones : null]];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages(['archivo' => $errores]);
        }

        return $datos;
    }

    /** @param array<string, mixed> $fila @param array<string, array> $reglas */
    private function validarFila(array $fila, array $reglas, string $hoja): void
    {
        $validacion = Validator::make($fila, $reglas, ['required' => 'Completá :attribute.', 'in' => 'Revisá :attribute: debe referenciar un valor permitido o un código de su hoja.', 'enum' => 'Revisá :attribute: usá los valores de la guía.', 'regex' => 'Revisá el formato de :attribute según la guía.', 'max' => ':attribute supera el límite de :max.', 'gt' => ':attribute debe ser mayor que cero.', 'between' => ':attribute debe estar entre :min y :max.', 'integer' => ':attribute debe ser entero.']);
        if ($validacion->fails()) {
            throw ValidationException::withMessages(['archivo' => array_map(fn (string $mensaje): string => $hoja.', fila '.($fila['_fila'] ?? '?').': '.$mensaje, $validacion->errors()->all())]);
        }
    }
}
