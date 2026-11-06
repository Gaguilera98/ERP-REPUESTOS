<?php

namespace App\Ventas;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoMovimientoInventario;
use App\Inventario\FactorUnidad;
use App\Models\Almacen;
use App\Models\Categoria;
use App\Models\InventarioStock;
use App\Models\Marca;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompraFlash
{
    public function __construct(
        private CostoPromedio $costoPromedio,
        private FactorUnidad $factores,
    ) {}

    /**
     * Micro-compra de emergencia: opcionalmente crea la pieza, recibe stock y confirma costo.
     *
     * @param  array{
     *     almacen_id: mixed,
     *     cantidad: mixed,
     *     costo_unitario: mixed,
     *     confirmo_costos: mixed,
     *     producto_variante_id?: mixed,
     *     producto_nuevo?: array{
     *         nombre: mixed,
     *         sku?: mixed,
     *         precio_venta: mixed,
     *         categoria_id?: mixed,
     *         marca_id?: mixed,
     *         unidad_base_id?: mixed
     *     }
     * }  $data
     * @return array{orden: OrdenCompra, variante: ProductoVariante}
     */
    public function ejecutar(array $data, TenantUser $usuario): array
    {
        if (! filter_var($data['confirmo_costos'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'confirmo_costos' => 'Confirmá el costo por unidad antes de la entrada express.',
            ]);
        }

        return DB::transaction(function () use ($data, $usuario): array {
            $almacenId = $this->id($data['almacen_id'] ?? null, 'almacen_id', 'Elegí el almacén.');

            if (! Almacen::query()->whereKey($almacenId)->exists()) {
                throw ValidationException::withMessages([
                    'almacen_id' => 'Ese almacén no es de este negocio.',
                ]);
            }

            $cantidad = $this->cantidad($data['cantidad'] ?? null, 'cantidad');
            $costoUnitario = $this->montoCuatro($data['costo_unitario'] ?? null, 'costo_unitario');

            if (Montos::comparar($costoUnitario, '0', 4) !== 1) {
                throw ValidationException::withMessages([
                    'costo_unitario' => 'El costo por unidad tiene que ser mayor a cero.',
                ]);
            }

            $variante = $this->resolverVariante($data);
            $variante = ProductoVariante::query()
                ->with('productoMaestro')
                ->whereKey($variante->id)
                ->lockForUpdate()
                ->firstOrFail();

            $unidadBaseId = (int) $variante->productoMaestro->unidad_base_id;
            $factor = $this->factores->haciaBase($variante->productoMaestro, $unidadBaseId);
            $cantidadBase = Montos::multiplicar($cantidad, $factor, 4);
            $subtotal = Montos::multiplicar($cantidad, $costoUnitario, 2);

            $ocasional = Proveedor::query()
                ->where('es_ocasional', true)
                ->lockForUpdate()
                ->first();

            if (! $ocasional instanceof Proveedor) {
                throw ValidationException::withMessages([
                    'proveedor_id' => 'Falta el proveedor ocasional del negocio.',
                ]);
            }

            $orden = OrdenCompra::query()->create([
                'proveedor_id' => $ocasional->id,
                'proveedor_contacto_id' => null,
                'user_id' => $usuario->id,
                'almacen_destino_id' => $almacenId,
                'es_compra_flash' => true,
                'estado' => EstadoOrdenCompra::Pendiente,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'persona_entrega_nombre' => null,
                'persona_recibe_trabajador_id' => null,
                'fecha_emision' => now(),
                'fecha_recepcion' => null,
            ]);

            $detalle = OrdenCompraDetalle::query()->create([
                'orden_compra_id' => $orden->id,
                'producto_variante_id' => $variante->id,
                'cantidad_pedida' => $cantidad,
                'cantidad_recibida' => '0.0000',
                'unidad_medida_id' => $unidadBaseId,
                'factor_a_base' => $factor,
                'cantidad_base' => $cantidadBase,
                'costo_anotado' => $costoUnitario,
                'moneda' => 'local',
                'costo_unitario_origen' => $costoUnitario,
                'tipo_cambio_id' => null,
                'tipo_cambio_valor' => null,
                'costo_unitario' => $costoUnitario,
                'subtotal_linea' => $subtotal,
            ]);

            InventarioStock::query()->firstOrCreate([
                'producto_variante_id' => $variante->id,
                'almacen_id' => $almacenId,
            ]);

            $stock = InventarioStock::query()
                ->where('producto_variante_id', $variante->id)
                ->where('almacen_id', $almacenId)
                ->lockForUpdate()
                ->firstOrFail();

            $stocksVariante = InventarioStock::query()
                ->where('producto_variante_id', $variante->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $stockTotal = '0.0000';

            foreach ($stocksVariante as $fila) {
                $stockTotal = Montos::sumar($stockTotal, (string) $fila->cantidad_actual, 4);
            }

            $previo = Montos::cuatro((string) $stock->cantidad_actual);
            $posterior = Montos::sumar($previo, $cantidadBase, 4);
            $promedio = $this->costoPromedio->resultante(
                (string) $variante->precio_costo,
                $stockTotal,
                $costoUnitario,
                $cantidadBase,
            );

            $stock->cantidad_actual = $posterior;
            $stock->save();

            $variante->precio_costo = $promedio;
            $variante->save();

            MovimientoInventario::query()->create([
                'producto_variante_id' => $variante->id,
                'almacen_id' => $almacenId,
                'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoInventario::EntradaFlashEmergencia,
                'cantidad' => $cantidadBase,
                'stock_previo' => $previo,
                'stock_posterior' => $posterior,
                'costo_unitario' => $costoUnitario,
                'costo_promedio_resultante' => $promedio,
                'referencia_tipo' => MovimientoInventario::REFERENCIA_ORDEN_COMPRA,
                'referencia_id' => $orden->id,
            ]);

            $detalle->cantidad_recibida = $cantidad;
            $detalle->save();

            $orden->estado = EstadoOrdenCompra::Completada;
            $orden->fecha_recepcion = now();
            $orden->save();

            return [
                'orden' => $orden->refresh()->load('detalles'),
                'variante' => $variante->fresh(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolverVariante(array $data): ProductoVariante
    {
        if (array_key_exists('producto_variante_id', $data) && $data['producto_variante_id'] !== null && $data['producto_variante_id'] !== '') {
            $id = $this->id($data['producto_variante_id'], 'producto_variante_id', 'Elegí el producto.');
            $variante = ProductoVariante::query()->find($id);

            if (! $variante instanceof ProductoVariante) {
                throw ValidationException::withMessages([
                    'producto_variante_id' => 'Ese producto no es de este negocio.',
                ]);
            }

            return $variante;
        }

        $nuevo = $data['producto_nuevo'] ?? null;

        if (! is_array($nuevo)) {
            throw ValidationException::withMessages([
                'producto_nuevo' => 'Indicá el producto existente o los datos del producto nuevo.',
            ]);
        }

        $nombre = is_string($nuevo['nombre'] ?? null) ? trim($nuevo['nombre']) : '';

        if ($nombre === '') {
            throw ValidationException::withMessages([
                'producto_nuevo.nombre' => 'El nombre del producto es obligatorio.',
            ]);
        }

        $precioVenta = $this->montoDos($nuevo['precio_venta'] ?? null, 'producto_nuevo.precio_venta');

        $categoriaId = $this->idOpcional($nuevo['categoria_id'] ?? null, 'producto_nuevo.categoria_id');

        if ($categoriaId === null) {
            $categoria = Categoria::query()->firstOrCreate(
                ['nombre' => 'General'],
                ['is_active' => true],
            );
            $categoriaId = $categoria->id;
        } elseif (! Categoria::query()->whereKey($categoriaId)->exists()) {
            throw ValidationException::withMessages([
                'producto_nuevo.categoria_id' => 'Esa categoría no es de este negocio.',
            ]);
        }

        $marcaId = $this->idOpcional($nuevo['marca_id'] ?? null, 'producto_nuevo.marca_id');

        if ($marcaId !== null && ! Marca::query()->whereKey($marcaId)->exists()) {
            throw ValidationException::withMessages([
                'producto_nuevo.marca_id' => 'Esa marca no es de este negocio.',
            ]);
        }

        $unidadId = $this->idOpcional($nuevo['unidad_base_id'] ?? null, 'producto_nuevo.unidad_base_id')
            ?? UnidadMedida::query()->where('simbolo', 'pza')->value('id');

        if ($unidadId === null || ! UnidadMedida::query()->whereKey($unidadId)->exists()) {
            throw ValidationException::withMessages([
                'producto_nuevo.unidad_base_id' => 'No hay unidad base disponible.',
            ]);
        }

        $sku = is_string($nuevo['sku'] ?? null) ? trim($nuevo['sku']) : '';

        if ($sku === '') {
            $sku = 'FLASH-'.strtoupper(Str::random(8));
        }

        if (ProductoVariante::query()->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages([
                'producto_nuevo.sku' => 'Ese SKU ya existe en el negocio.',
            ]);
        }

        $maestro = ProductoMaestro::query()->create([
            'nombre' => $nombre,
            'categoria_id' => $categoriaId,
            'marca_id' => $marcaId,
            'unidad_base_id' => $unidadId,
            'is_active' => true,
        ]);

        return ProductoVariante::query()->create([
            'producto_maestro_id' => $maestro->id,
            'sku' => $sku,
            'precio_venta' => $precioVenta,
            'precio_costo' => '0.0000',
            'is_active' => true,
        ]);
    }

    private function id(mixed $valor, string $campo, string $mensaje): int
    {
        if (is_int($valor) && $valor > 0) {
            return $valor;
        }

        if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        throw ValidationException::withMessages([
            $campo => $mensaje,
        ]);
    }

    private function idOpcional(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->id($valor, $campo, 'Identificador inválido.');
    }

    private function cantidad(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 4, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad tiene que ser un número mayor a cero.',
            ]);
        }

        $cantidad = Montos::redondear($texto, 4);

        if (Montos::comparar($cantidad, '0', 4) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad tiene que ser un número mayor a cero.',
            ]);
        }

        return $cantidad;
    }

    private function montoCuatro(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 4, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El costo tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::cuatro($texto);
    }

    private function montoDos(mixed $valor, string $campo): string
    {
        $texto = match (true) {
            is_int($valor) => (string) $valor,
            is_float($valor) => number_format($valor, 2, '.', ''),
            is_string($valor) => trim($valor),
            default => null,
        };

        if ($texto === null || preg_match('/^\d+(\.\d+)?$/', $texto) !== 1) {
            throw ValidationException::withMessages([
                $campo => 'El precio de venta tiene que ser un número de cero para arriba.',
            ]);
        }

        return Montos::dos($texto);
    }
}
