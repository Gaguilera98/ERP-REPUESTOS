<?php

namespace App\Compras;

use App\Enums\EstadoOrdenCompra;
use App\Inventario\FactorUnidad;
use App\Models\Almacen;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\TenantUser;
use App\Models\Trabajador;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardarOrdenCompra
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function crear(array $data, TenantUser $usuario): OrdenCompra
    {
        return DB::transaction(function () use ($data, $usuario): OrdenCompra {
            $lineas = $this->lineas($data['lineas'] ?? null);
            $cabecera = $this->cabecera($data);

            $orden = OrdenCompra::query()->create([
                ...$cabecera,
                'user_id' => $usuario->id,
                'es_compra_flash' => false,
                'estado' => EstadoOrdenCompra::Pendiente,
                'subtotal' => 0,
                'total' => 0,
                'fecha_recepcion' => null,
            ]);

            $this->crearLineas($orden, $lineas);
            $orden->save();

            return $orden->refresh()->load('detalles');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function actualizar(OrdenCompra $orden, array $data): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $data): OrdenCompra {
            $orden = OrdenCompra::query()->whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if ($orden->estado === EstadoOrdenCompra::Anulada) {
                throw ValidationException::withMessages([
                    'estado' => 'Una compra anulada no se edita.',
                ]);
            }

            if ($orden->estado !== EstadoOrdenCompra::Pendiente) {
                throw ValidationException::withMessages([
                    'estado' => 'Una compra recibida no se edita.',
                ]);
            }

            $lineas = $this->lineas($data['lineas'] ?? null);
            $cabecera = $this->cabecera($data, $orden);

            $orden->fill([
                ...$cabecera,
                'es_compra_flash' => false,
            ]);
            $orden->save();
            $orden->detalles()->delete();
            $this->crearLineas($orden, $lineas);
            $orden->save();

            return $orden->refresh()->load('detalles');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     proveedor_id: int,
     *     proveedor_contacto_id: int|null,
     *     almacen_destino_id: int,
     *     persona_entrega_nombre: string|null,
     *     persona_recibe_trabajador_id: int|null,
     *     fecha_emision: mixed
     * }
     */
    private function cabecera(array $data, ?OrdenCompra $orden = null): array
    {
        $proveedorId = $this->id($data['proveedor_id'] ?? null, 'proveedor_id', 'Elegí el proveedor.');

        if (! Proveedor::query()->whereKey($proveedorId)->exists()) {
            throw ValidationException::withMessages([
                'proveedor_id' => 'Ese proveedor no es de este negocio.',
            ]);
        }

        $contactoId = $this->idOpcional($data['proveedor_contacto_id'] ?? null, 'proveedor_contacto_id');

        if ($contactoId !== null && ! ProveedorContacto::query()->whereKey($contactoId)->where('proveedor_id', $proveedorId)->exists()) {
            throw ValidationException::withMessages([
                'proveedor_contacto_id' => 'Ese contacto no es de este proveedor.',
            ]);
        }

        $almacenId = array_key_exists('almacen_destino_id', $data)
            ? $this->id($data['almacen_destino_id'], 'almacen_destino_id', 'Elegí el almacén que recibe.')
            : (int) $orden?->almacen_destino_id;

        if (! Almacen::query()->whereKey($almacenId)->exists()) {
            throw ValidationException::withMessages([
                'almacen_destino_id' => 'Ese almacén no es de este negocio.',
            ]);
        }

        $trabajadorId = $this->idOpcional($data['persona_recibe_trabajador_id'] ?? null, 'persona_recibe_trabajador_id');

        if ($trabajadorId !== null && ! Trabajador::query()->whereKey($trabajadorId)->exists()) {
            throw ValidationException::withMessages([
                'persona_recibe_trabajador_id' => 'Esa persona no es de este negocio.',
            ]);
        }

        $entrega = $data['persona_entrega_nombre'] ?? null;
        $entrega = is_string($entrega) ? trim($entrega) : null;

        return [
            'proveedor_id' => $proveedorId,
            'proveedor_contacto_id' => $contactoId,
            'almacen_destino_id' => $almacenId,
            'persona_entrega_nombre' => $entrega !== '' ? $entrega : null,
            'persona_recibe_trabajador_id' => $trabajadorId,
            'fecha_emision' => $data['fecha_emision'] ?? $orden?->fecha_emision ?? now(),
        ];
    }

    /**
     * @return list<array{producto_variante_id: int, unidad_medida_id: int, factor_a_base: string, cantidad_pedida: string, costo_anotado: string, costo_unitario: string}>
     */
    private function lineas(mixed $lineas): array
    {
        if (! is_array($lineas) || $lineas === []) {
            throw ValidationException::withMessages([
                'lineas' => 'Agregá al menos un producto.',
            ]);
        }

        $normalizadas = [];
        $vistos = [];

        foreach (array_values($lineas) as $indice => $linea) {
            if (! is_array($linea)) {
                throw ValidationException::withMessages([
                    'lineas' => 'Agregá al menos un producto.',
                ]);
            }

            $varianteId = $this->id(
                $linea['producto_variante_id'] ?? null,
                "lineas.{$indice}.producto_variante_id",
                'Elegí un producto.',
            );

            $variante = ProductoVariante::query()->with('productoMaestro')->find($varianteId);

            if (! $variante instanceof ProductoVariante || ! $variante->productoMaestro instanceof ProductoMaestro) {
                throw ValidationException::withMessages([
                    "lineas.{$indice}.producto_variante_id" => 'Ese producto no es de este negocio.',
                ]);
            }

            if (isset($vistos[$varianteId])) {
                throw ValidationException::withMessages([
                    'lineas' => 'Cada producto entra una sola vez en la compra.',
                ]);
            }

            $vistos[$varianteId] = true;
            $unidadId = $this->idOpcional($linea['unidad_medida_id'] ?? null, "lineas.{$indice}.unidad_medida_id")
                ?? (int) $variante->productoMaestro->unidad_base_id;
            $unidad = UnidadMedida::query()->find($unidadId);
            $cantidad = $this->cantidad($linea['cantidad_pedida'] ?? null, "lineas.{$indice}.cantidad_pedida");
            $this->rechazarFraccion($cantidad, $unidad, "lineas.{$indice}.cantidad_pedida");
            $factor = app(FactorUnidad::class)->haciaBase($variante->productoMaestro, $unidadId);
            $costo = $this->costo($linea['costo_unitario'] ?? null, "lineas.{$indice}.costo_unitario");

            $normalizadas[] = [
                'producto_variante_id' => $varianteId,
                'unidad_medida_id' => $unidadId,
                'factor_a_base' => $factor,
                'cantidad_pedida' => $cantidad,
                'costo_anotado' => $costo,
                'costo_unitario' => $costo,
            ];
        }

        return $normalizadas;
    }

    /**
     * @param  list<array{producto_variante_id: int, unidad_medida_id: int, factor_a_base: string, cantidad_pedida: string, costo_anotado: string, costo_unitario: string}>  $lineas
     */
    private function crearLineas(OrdenCompra $orden, array $lineas): void
    {
        foreach ($lineas as $linea) {
            OrdenCompraDetalle::query()->create([
                'orden_compra_id' => $orden->id,
                'producto_variante_id' => $linea['producto_variante_id'],
                'unidad_medida_id' => $linea['unidad_medida_id'],
                'factor_a_base' => $linea['factor_a_base'],
                'cantidad_pedida' => $linea['cantidad_pedida'],
                'cantidad_recibida' => 0,
                'costo_anotado' => $linea['costo_anotado'],
                'costo_unitario' => $linea['costo_unitario'],
                'costo_unitario_origen' => $linea['costo_anotado'],
            ]);
        }
    }

    private function id(mixed $valor, string $campo, string $mensaje): int
    {
        $texto = is_int($valor) ? (string) $valor : (is_string($valor) ? trim($valor) : null);

        if ($texto === null || preg_match('/^[1-9]\d*$/', $texto) !== 1) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        return (int) $texto;
    }

    private function idOpcional(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->id($valor, $campo, 'Elegí un registro válido.');
    }

    private function cantidad(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 4, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1 || Montos::comparar($texto, '0') !== 1) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad tiene que ser mayor a cero.',
            ]);
        }

        return Montos::redondear($texto, 4);
    }

    private function rechazarFraccion(string $cantidad, ?UnidadMedida $unidad, string $campo): void
    {
        if (! $unidad instanceof UnidadMedida || $unidad->admiteCantidad($cantidad)) {
            return;
        }

        throw ValidationException::withMessages([
            $campo => 'Esa unidad no acepta fracciones.',
        ]);
    }

    private function entero(mixed $valor, string $campo, string $mensaje): int
    {
        $texto = is_int($valor) ? (string) $valor : (is_string($valor) ? trim($valor) : null);

        if ($texto === null || preg_match('/^\d+$/', $texto) !== 1) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        return (int) $texto;
    }

    private function costo(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_string($valor) => trim($valor),
            is_float($valor) => number_format($valor, 4, '.', ''),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El costo por unidad tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::cuatro($texto);
    }
}
