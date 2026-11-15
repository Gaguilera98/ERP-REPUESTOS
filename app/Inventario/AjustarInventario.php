<?php

namespace App\Inventario;

use App\Compras\Montos;
use App\Enums\EstadoAjusteInventario;
use App\Enums\EstadoVenta;
use App\Enums\MotivoAjusteInventario;
use App\Enums\TipoAjusteInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\AjusteInventario;
use App\Models\InventarioStock;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\Venta;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AjustarInventario
{
    public function __construct(private RegistrarMovimiento $movimientos) {}

    /**
     * @param  list<array{producto_variante_id: mixed, cantidad_fisica: mixed}>  $lineas
     */
    public function abrirConteo(int $almacenId, array $lineas, TenantUser $usuario): AjusteInventario
    {
        app(AccesoSucursales::class)->validarAlmacen($usuario, $almacenId);
        abort_unless($usuario->can('Cargar:Conteo'), 403);

        return DB::transaction(function () use ($almacenId, $lineas, $usuario): AjusteInventario {
            $this->movimientos->exigirLibre($almacenId);
            $ajuste = AjusteInventario::query()->create([
                'almacen_id' => $almacenId,
                'user_id' => $usuario->id,
                'tipo' => TipoAjusteInventario::Conteo,
                'estado' => EstadoAjusteInventario::EnCurso,
            ]);
            $this->guardarLineas($ajuste, $lineas, MotivoAjusteInventario::DiferenciaConteo);

            return $ajuste->load('detalles');
        });
    }

    /**
     * @param  array{producto_variante_id: mixed, motivo: mixed, cantidad: mixed, observacion: mixed}  $datos
     */
    public function puntual(int $almacenId, array $datos, TenantUser $usuario): AjusteInventario
    {
        app(AccesoSucursales::class)->validarAlmacen($usuario, $almacenId);
        abort_unless($usuario->can('Ajustar:Inventario'), 403);
        $motivo = MotivoAjusteInventario::tryFrom((string) ($datos['motivo'] ?? ''));
        $observacion = trim((string) ($datos['observacion'] ?? ''));

        if (! $motivo instanceof MotivoAjusteInventario || $motivo === MotivoAjusteInventario::DiferenciaConteo || $observacion === '') {
            throw ValidationException::withMessages(['ajuste' => 'Elegí el motivo y escribí qué pasó.']);
        }

        return DB::transaction(function () use ($almacenId, $datos, $usuario, $motivo, $observacion): AjusteInventario {
            $this->movimientos->exigirLibre($almacenId);
            $variante = $this->variante($datos['producto_variante_id'] ?? null);
            $stock = $this->stock($variante, $almacenId);
            $cantidad = Montos::cuatro((string) ($datos['cantidad'] ?? '0'));

            if (Montos::comparar($cantidad, '0', 4) !== 1) {
                throw ValidationException::withMessages(['cantidad' => 'La cantidad tiene que ser mayor a cero.']);
            }

            $fisica = $motivo->suma()
                ? Montos::sumar((string) $stock->cantidad_actual, $cantidad, 4)
                : Montos::sumar((string) $stock->cantidad_actual, bcmul($cantidad, '-1', 4), 4);

            $ajuste = AjusteInventario::query()->create([
                'almacen_id' => $almacenId,
                'user_id' => $usuario->id,
                'supervisor_id' => $usuario->id,
                'tipo' => TipoAjusteInventario::Puntual,
                'estado' => EstadoAjusteInventario::Cerrado,
                'observacion' => $observacion,
            ]);
            $ajuste->detalles()->create([
                'producto_variante_id' => $variante->id,
                'motivo' => $motivo,
                'cantidad_sistema' => $stock->cantidad_actual,
                'cantidad_fisica' => $fisica,
            ]);
            $this->aplicarDiferencia($ajuste, $variante, $stock, $fisica, $usuario);

            return $ajuste->load('detalles');
        });
    }

    public function cerrarConteo(AjusteInventario $ajuste, TenantUser $usuario): AjusteInventario
    {
        app(AccesoSucursales::class)->validarAlmacen($usuario, (int) $ajuste->almacen_id);
        abort_unless($usuario->can('Cerrar:Conteo'), 403);

        return DB::transaction(function () use ($ajuste, $usuario): AjusteInventario {
            $ajuste = AjusteInventario::query()->lockForUpdate()->findOrFail($ajuste->id);

            if ($ajuste->tipo !== TipoAjusteInventario::Conteo || $ajuste->estado !== EstadoAjusteInventario::EnCurso) {
                throw ValidationException::withMessages(['conteo' => 'Ese conteo ya está cerrado.']);
            }

            foreach ($ajuste->detalles()->with('variante')->orderBy('id')->get() as $detalle) {
                $variante = $detalle->variante;

                if (! $variante instanceof ProductoVariante) {
                    throw ValidationException::withMessages(['conteo' => 'Falta un producto del conteo.']);
                }

                $stock = $this->stock($variante, $ajuste->almacen_id);

                if (Montos::comparar((string) $detalle->cantidad_sistema, (string) $stock->cantidad_actual, 4) !== 0) {
                    throw ValidationException::withMessages([
                        'conteo' => 'El stock de '.$variante->sku.' cambió desde que lo contaste. Volvé a contarlo.',
                    ]);
                }

                if (Montos::comparar((string) $detalle->cantidad_fisica, (string) $stock->cantidad_reservada, 4) === -1) {
                    throw ValidationException::withMessages([
                        'conteo' => 'No se puede cerrar '.$variante->sku.'. Estas notas la tienen apartada: '.$this->notasApartadas($ajuste->almacen_id, $variante->id).'.',
                    ]);
                }

                $this->aplicarDiferencia($ajuste, $variante, $stock, (string) $detalle->cantidad_fisica, $usuario);
            }

            $ajuste->estado = EstadoAjusteInventario::Cerrado;
            $ajuste->supervisor_id = $usuario->id;
            $ajuste->save();

            return $ajuste->load('detalles');
        });
    }

    /**
     * @param  list<array{producto_variante_id: mixed, cantidad_fisica: mixed}>  $lineas
     */
    private function guardarLineas(AjusteInventario $ajuste, array $lineas, MotivoAjusteInventario $motivo): void
    {
        if ($lineas === []) {
            throw ValidationException::withMessages(['lineas' => 'Contá al menos una pieza.']);
        }

        $vistas = [];

        foreach ($lineas as $linea) {
            $variante = $this->variante($linea['producto_variante_id'] ?? null);

            if (isset($vistas[$variante->id])) {
                throw ValidationException::withMessages(['lineas' => $variante->sku.' está repetido.']);
            }

            if (! array_key_exists('cantidad_fisica', $linea) || $linea['cantidad_fisica'] === '' || $linea['cantidad_fisica'] === null) {
                throw ValidationException::withMessages(['lineas' => 'Escribí la cantidad de '.$variante->sku.'. Un cero hay que escribirlo.']);
            }

            $fisica = Montos::cuatro((string) $linea['cantidad_fisica']);

            if (Montos::comparar($fisica, '0', 4) === -1) {
                throw ValidationException::withMessages(['lineas' => 'La cantidad de '.$variante->sku.' no puede ser negativa.']);
            }

            $stock = $this->stock($variante, $ajuste->almacen_id);
            $vistas[$variante->id] = true;
            $ajuste->detalles()->create([
                'producto_variante_id' => $variante->id,
                'motivo' => $motivo,
                'cantidad_sistema' => $stock->cantidad_actual,
                'cantidad_fisica' => $fisica,
            ]);
        }
    }

    private function aplicarDiferencia(AjusteInventario $ajuste, ProductoVariante $variante, InventarioStock $stock, string $fisica, TenantUser $usuario): void
    {
        $sistema = Montos::cuatro((string) $stock->cantidad_actual);
        $comparacion = Montos::comparar($fisica, $sistema, 4);

        if ($comparacion === 0) {
            return;
        }

        $this->movimientos->mover([
            'variante' => $variante,
            'almacen_id' => $ajuste->almacen_id,
            'user_id' => $usuario->id,
            'tipo' => TipoMovimientoInventario::Ajuste,
            'cantidad' => $comparacion === 1 ? Montos::sumar($fisica, bcmul($sistema, '-1', 4), 4) : Montos::sumar($sistema, bcmul($fisica, '-1', 4), 4),
            'sentido' => $comparacion === 1 ? 'entrada' : 'salida',
            'costo_unitario' => (string) $variante->precio_costo,
            'costo_promedio_resultante' => (string) $variante->precio_costo,
            'referencia_tipo' => 'ajuste',
            'referencia_id' => $ajuste->id,
        ]);
    }

    private function variante(mixed $id): ProductoVariante
    {
        $variante = ProductoVariante::query()->find($id);

        if (! $variante instanceof ProductoVariante) {
            throw ValidationException::withMessages(['lineas' => 'Elegí un producto del negocio.']);
        }

        return $variante;
    }

    private function stock(ProductoVariante $variante, int $almacenId): InventarioStock
    {
        $stock = InventarioStock::query()
            ->where('producto_variante_id', $variante->id)
            ->where('almacen_id', $almacenId)
            ->lockForUpdate()
            ->first();

        if (! $stock instanceof InventarioStock) {
            $stock = InventarioStock::query()->create([
                'producto_variante_id' => $variante->id,
                'almacen_id' => $almacenId,
            ]);
            $stock = InventarioStock::query()->whereKey($stock->id)->lockForUpdate()->firstOrFail();
        }

        return $stock;
    }

    private function notasApartadas(int $almacenId, int $varianteId): string
    {
        $codigos = Venta::query()
            ->where('almacen_id', $almacenId)
            ->where('estado', EstadoVenta::Reserva)
            ->whereHas('detalles', fn ($query) => $query->where('producto_variante_id', $varianteId))
            ->orderBy('codigo')
            ->pluck('codigo');

        return $codigos->isEmpty() ? 'una reserva sin código' : $codigos->implode(', ');
    }
}
