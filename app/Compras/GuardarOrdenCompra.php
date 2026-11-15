<?php

namespace App\Compras;

use App\Catalogo\PreciosDeReferencia;
use App\Enums\EstadoOrdenCompra;
use App\Enums\ModoMoneda;
use App\Enums\MonedaLineaCompra;
use App\Inventario\FactorUnidad;
use App\Models\Almacen;
use App\Models\NegocioConfiguracion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraDetalle;
use App\Models\ProductoMaestro;
use App\Models\ProductoVariante;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\TenantUser;
use App\Models\Trabajador;
use App\Models\UnidadMedida;
use App\Tenancy\AccesoSucursales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardarOrdenCompra
{
    public function __construct(private PreciosDeReferencia $precios) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function crear(array $data, TenantUser $usuario): OrdenCompra
    {
        return DB::transaction(function () use ($data, $usuario): OrdenCompra {
            $lineas = $this->lineas($data['lineas'] ?? null);
            $cabecera = $this->cabecera($data);
            app(AccesoSucursales::class)->validarAlmacen($usuario, $cabecera['almacen_destino_id'], 'almacen_destino_id');

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
    public function actualizar(OrdenCompra $orden, array $data, TenantUser $usuario): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $data, $usuario): OrdenCompra {
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

            $lineas = $this->lineas($data['lineas'] ?? null, $orden);
            $cabecera = $this->cabecera($data, $orden);
            $acceso = app(AccesoSucursales::class);
            $acceso->validarAlmacen($usuario, (int) $orden->almacen_destino_id, 'almacen_destino_id');
            $acceso->validarAlmacen($usuario, $cabecera['almacen_destino_id'], 'almacen_destino_id');

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
     * @return list<array<string, mixed>>
     */
    private function lineas(mixed $lineas, ?OrdenCompra $orden = null): array
    {
        if (! is_array($lineas) || $lineas === []) {
            throw ValidationException::withMessages([
                'lineas' => 'Agregá al menos un producto.',
            ]);
        }

        $normalizadas = [];
        $vistos = [];
        $configuracion = NegocioConfiguracion::query()->first();
        $anteriores = $orden?->detalles()->get()->keyBy('producto_variante_id');
        $cotizacionComercial = null;
        $subtotal = '0.00';

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
            $valorMoneda = $linea['moneda'] ?? MonedaLineaCompra::Local->value;
            $moneda = is_string($valorMoneda) ? MonedaLineaCompra::tryFrom($valorMoneda) : null;
            $anterior = $anteriores?->get($varianteId);
            if ($moneda === null || ($moneda === MonedaLineaCompra::Usd
                && $configuracion?->modo_moneda !== ModoMoneda::ConDolar
                && $anterior?->moneda !== MonedaLineaCompra::Usd)) {
                throw ValidationException::withMessages(["lineas.{$indice}.moneda" => 'Elegí una moneda habilitada para esta compra.']);
            }

            $cambio = null;
            $tipoCambioId = null;
            if ($moneda === MonedaLineaCompra::Usd) {
                if ($anterior?->moneda === MonedaLineaCompra::Usd) {
                    $cambio = $anterior->tipo_cambio_valor;
                    $tipoCambioId = $anterior->tipo_cambio_id;
                } else {
                    $cotizacionComercial ??= $this->precios->vigente($configuracion->moneda->value);
                    $cambio = $cotizacionComercial?->valor;
                    $tipoCambioId = $cotizacionComercial?->id;
                }

                if ($cambio === null) {
                    throw ValidationException::withMessages(['data.lineas' => 'Publicá un tipo de cambio antes de registrar un costo en USD.']);
                }
            }
            $costoLocal = $cambio === null ? $costo : Montos::multiplicar($costo, $cambio, 4);
            $subtotalLinea = Montos::multiplicar($cantidad, $costoLocal, 2);
            if (Montos::comparar($costo, '9999999999.9999') > 0
                || Montos::comparar(Montos::dividir($costoLocal, $factor, 4), '9999999999.9999') > 0
                || Montos::comparar($subtotalLinea, '99999999.99', 2) > 0) {
                throw ValidationException::withMessages(["lineas.{$indice}.costo_unitario" => 'El costo o el subtotal convertido supera el máximo de la compra.']);
            }
            $subtotal = Montos::sumar($subtotal, $subtotalLinea, 2);

            $normalizadas[] = [
                'producto_variante_id' => $varianteId,
                'unidad_medida_id' => $unidadId,
                'factor_a_base' => $factor,
                'cantidad_pedida' => $cantidad,
                'costo_anotado' => $costo,
                'moneda' => $moneda,
                'tipo_cambio_valor' => $cambio,
                'tipo_cambio_id' => $tipoCambioId,
            ];
        }

        if (Montos::comparar($subtotal, '99999999.99', 2) > 0) {
            throw ValidationException::withMessages(['lineas' => 'El total convertido supera el máximo de la compra.']);
        }

        return $normalizadas;
    }

    /**
     * @param  list<array<string, mixed>>  $lineas
     */
    private function crearLineas(OrdenCompra $orden, array $lineas): void
    {
        foreach ($lineas as $linea) {
            OrdenCompraDetalle::query()->create([
                ...$linea,
                'orden_compra_id' => $orden->id,
                'cantidad_recibida' => 0,
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
