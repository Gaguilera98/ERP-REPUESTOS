<?php

namespace App\Ventas;

use App\Compras\Montos;
use App\Enums\CondicionVenta;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoDescuento;
use App\Enums\TipoMovimientoInventario;
use App\Enums\TipoPrecioCliente;
use App\Models\CajaSesion;
use App\Models\CorreccionVenta;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorregirVenta
{
    public function __construct(private PrecioDeLista $precios) {}

    /** @return list<array<string, mixed>> */
    public function lineasVigentes(Venta $venta): array
    {
        return $venta->ultimaCorreccion?->lineas ?? $venta->detalles()
            ->orderBy('id')->get()->map(fn ($linea): array => $linea->only([
                'producto_variante_id', 'unidad_medida_id', 'factor_a_base', 'cantidad', 'cantidad_base',
                'precio_unitario', 'precio_costo_snapshot', 'tipo_descuento', 'valor_descuento',
                'descuento_aplicado', 'total_linea',
            ]))->all();
    }

    /** @return array{lineas: list<array<string,mixed>>, total: string, cobrado: string, saldo: string, cobrar: string, devolver: string, reembolsos: list<array{origen: int, metodo: string, monto: string}>, cambios: list<array{variante: int, sku: string, cantidad: string, costo: ?string}>} */
    public function revisar(Venta $venta, array $edicion): array
    {
        if (! $venta->estaCompletada() || $venta->tipo_comprobante !== TipoComprobante::NotaVenta) {
            throw ValidationException::withMessages(['lineas' => 'Solo se pueden corregir notas de venta completadas.']);
        }
        if ($venta->aplicacionesPago()->whereHas('pago', fn ($query) => $query->where('estado_pago', EstadoPago::Pendiente))->exists()) {
            throw ValidationException::withMessages(['lineas' => 'Hay pagos pendientes. Resolvelos antes de corregir.']);
        }

        validator(['lineas' => $edicion], [
            'lineas' => ['required', 'array', 'max:200'],
            'lineas.*.origen' => ['nullable', 'integer', 'min:0', 'distinct'],
            'lineas.*.producto_variante_id' => ['required', 'integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', 'gt:0'],
        ])->validate();

        $anteriores = $this->lineasVigentes($venta);
        $variantes = ProductoVariante::query()->with('productoMaestro.unidadBase')
            ->whereKey(collect($edicion)->pluck('producto_variante_id')->merge(array_column($anteriores, 'producto_variante_id'))
                ->map(fn ($id): int => (int) $id)->unique()->values()->all())->get()->keyBy('id');
        $productosOriginales = array_map('intval', array_column($anteriores, 'producto_variante_id'));
        $unidades = UnidadMedida::query()->whereKey(array_unique(array_column($anteriores, 'unidad_medida_id')))->get()->keyBy('id');
        $lineas = [];
        $cambios = [];
        $total = '0.00';

        foreach ($edicion as $fila) {
            $origen = $fila['origen'] ?? null;
            $anterior = $origen !== null ? ($anteriores[(int) $origen] ?? null) : null;
            $variante = $variantes->get((int) $fila['producto_variante_id']);

            if (! $variante || ($origen !== null && ! $anterior)) {
                throw ValidationException::withMessages(['lineas' => 'El producto seleccionado ya no corresponde a esa línea.']);
            }
            if ($anterior && (int) $anterior['producto_variante_id'] !== $variante->id) {
                $anterior = null;
            }
            if ($anterior === null && (! $variante->is_active || ! $variante->productoMaestro?->is_active)) {
                throw ValidationException::withMessages(['lineas' => 'Elegí un producto activo para la línea nueva.']);
            }
            if ($anterior === null && in_array($variante->id, $productosOriginales, true)) {
                throw ValidationException::withMessages(['lineas' => 'Ajustá la cantidad de la línea existente para conservar su precio original.']);
            }

            $unidad = $anterior ? $unidades->get($anterior['unidad_medida_id']) : $variante->productoMaestro?->unidadBase;
            $cantidad = Montos::cuatro((string) $fila['cantidad']);

            if (! $unidad?->admiteCantidad($cantidad)) {
                throw ValidationException::withMessages(['lineas' => 'La cantidad no es válida para la unidad de '.$variante->sku.'.']);
            }

            $factor = $anterior['factor_a_base'] ?? '1.000000';
            $base = Montos::multiplicar($cantidad, $factor, 4);
            if (bccomp($base, '0', 4) <= 0 || bccomp($base, '9999999999.9999', 4) > 0) {
                throw ValidationException::withMessages(['lineas' => 'La cantidad base está fuera del límite permitido.']);
            }

            $precio = $anterior['precio_unitario'] ?? $this->precios->de($variante, $venta->cliente?->tipo_precio ?? TipoPrecioCliente::Menor);
            $bruto = Montos::multiplicar($base, $precio, 2);
            $descuento = $this->descuento($bruto, $anterior['tipo_descuento'] ?? null, $anterior['valor_descuento'] ?? '0');
            $neto = bcsub($bruto, $descuento, 2);
            $delta = bcsub($base, $anterior['cantidad_base'] ?? '0', 4);
            $costo = $anterior['precio_costo_snapshot'] ?? $variante->precio_costo;

            if (bccomp($delta, '0', 4) !== 0) {
                $cambios[] = ['variante' => $variante->id, 'sku' => $variante->sku, 'cantidad' => $delta, 'costo' => $delta[0] === '-' ? $costo : null];
            }
            if ($anterior && bccomp($delta, '0', 4) > 0) {
                $valor = bcadd(bcmul($anterior['cantidad_base'], $costo, 8), bcmul($delta, $variante->precio_costo, 8), 8);
                $costo = Montos::dividir($valor, $base, 4);
            }

            $lineas[] = [
                'producto_variante_id' => $variante->id, 'unidad_medida_id' => $unidad->id,
                'factor_a_base' => $factor, 'cantidad' => $cantidad, 'cantidad_base' => $base,
                'precio_unitario' => $precio, 'precio_costo_snapshot' => $costo,
                'tipo_descuento' => $anterior['tipo_descuento'] ?? null,
                'valor_descuento' => $anterior['valor_descuento'] ?? '0.00',
                'descuento_aplicado' => $descuento, 'total_linea' => $neto,
                'producto' => $variante->productoMaestro?->nombre ?? $variante->sku,
                'sku' => $variante->sku, 'unidad' => $unidad->simbolo,
            ];
            $total = bcadd($total, $neto, 2);
            if ($anterior !== null) {
                unset($anteriores[(int) $origen]);
            }
        }

        foreach ($anteriores as $linea) {
            $cambios[] = ['variante' => (int) $linea['producto_variante_id'],
                'sku' => $variantes->get($linea['producto_variante_id'])?->sku ?? '#'.$linea['producto_variante_id'],
                'cantidad' => bcsub('0', $linea['cantidad_base'], 4), 'costo' => $linea['precio_costo_snapshot']];
        }

        if ($cambios === []) {
            throw ValidationException::withMessages(['lineas' => 'Cambiá algún producto o cantidad para continuar.']);
        }

        $total = bcsub($total, $this->descuento($total, $venta->tipo_descuento, $venta->valor_descuento), 2);
        if (bccomp($total, '0', 2) <= 0 || bccomp($total, '99999999.99', 2) > 0) {
            throw ValidationException::withMessages(['lineas' => 'El total corregido está fuera del límite permitido.']);
        }

        $cobrado = $venta->cobradoNeto();
        $diferencia = bcsub($total, $cobrado, 2);
        $faltante = bccomp($diferencia, '0', 2) > 0 ? $diferencia : '0.00';
        $esCredito = $venta->condicion === CondicionVenta::Credito;
        $devolver = bccomp($diferencia, '0', 2) < 0 ? bcsub('0', $diferencia, 2) : '0.00';

        return [
            'lineas' => $lineas, 'total' => $total, 'cobrado' => $cobrado, 'saldo' => $esCredito ? $faltante : '0.00',
            'cobrar' => $esCredito ? '0.00' : $faltante, 'devolver' => $devolver,
            'reembolsos' => $this->distribuirReembolso($venta, $devolver), 'cambios' => $cambios,
        ];
    }

    public function confirmar(Venta $venta, array $lineas, string $motivo, string $revision, TenantUser $usuario): void
    {
        $motivo = trim($motivo);
        validator(['motivo' => $motivo], ['motivo' => ['required', 'string', 'max:500']])->validate();

        DB::transaction(function () use ($venta, $lineas, $motivo, $revision, $usuario): void {
            $caja = $this->cajaAbierta($venta, $usuario)->lockForUpdate()->first();

            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            abort_unless($usuario->can('corregir', $venta), 403);

            $variantes = ProductoVariante::query()
                ->whereKey(collect($lineas)->pluck('producto_variante_id')->merge(array_column($this->lineasVigentes($venta), 'producto_variante_id'))
                    ->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id))->unique()->values()->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $resumen = $this->revisar($venta, $lineas);

            if (! hash_equals($this->revision($venta, $resumen), $revision)) {
                throw ValidationException::withMessages(['lineas' => 'La venta, los precios o los pagos cambiaron. Volvé a revisar la corrección.']);
            }

            $mueveCaja = $resumen['cobrar'] !== '0.00' || $resumen['devolver'] !== '0.00';
            if ($mueveCaja && (! $caja || ! $caja->esDeHoy())) {
                throw ValidationException::withMessages(['lineas' => 'Abrí tu caja de hoy en la sucursal de la venta para registrar la diferencia.']);
            }

            $correccion = $venta->correcciones()->create([
                'user_id' => $usuario->id, 'caja_sesion_id' => $mueveCaja ? $caja->id : null,
                'motivo' => $motivo, 'lineas' => $resumen['lineas'], 'total' => $resumen['total'],
            ]);
            $this->ajustarInventario($venta, $correccion, $resumen['cambios'], $variantes, $usuario);

            if ($resumen['cobrar'] !== '0.00') {
                $this->registrarDiferencia($venta, $caja, SentidoPago::Cobro, MetodoPago::Efectivo, $resumen['cobrar']);
            }

            foreach ($resumen['reembolsos'] as $reembolso) {
                $this->registrarDiferencia($venta, $caja, SentidoPago::Reembolso, MetodoPago::from($reembolso['metodo']), $reembolso['monto'], $reembolso['origen']);
            }
        }, attempts: 3);
    }

    public function revision(Venta $venta, array $resumen): string
    {
        return hash('sha256', serialize([$venta->id, $venta->ultimaCorreccion?->id, $resumen]));
    }

    public function revisarCaja(Venta $venta, array $resumen, TenantUser $usuario): void
    {
        if (($resumen['cobrar'] !== '0.00' || $resumen['devolver'] !== '0.00') && ! $this->cajaAbierta($venta, $usuario)->exists()) {
            throw ValidationException::withMessages(['lineas' => 'Abrí tu caja de hoy en la sucursal de la venta para registrar la diferencia.']);
        }
    }

    /** @return Builder<CajaSesion> */
    private function cajaAbierta(Venta $venta, TenantUser $usuario): Builder
    {
        return CajaSesion::query()->where('sucursal_id', $venta->sucursal_id)
            ->where('user_id', $usuario->id)->where('estado_sesion', EstadoCajaSesion::Abierta)
            ->whereDate('fecha_apertura', now()->toDateString());
    }

    /** @return list<array{origen: int, metodo: string, saldo_favor: bool, monto: string}> */
    public function distribuirReembolso(Venta $venta, string $monto): array
    {
        if ($monto === '0.00') {
            return [];
        }

        $aplicaciones = $venta->aplicacionesPago()->with(['pago', 'usos'])
            ->whereHas('pago', fn ($query) => $query->where('estado_pago', EstadoPago::Aprobado))
            ->orderByDesc('id')->get();
        $reembolsos = [];

        foreach ($aplicaciones as $aplicacion) {
            if ($aplicacion->pago->sentido !== SentidoPago::Cobro || $monto === '0.00') {
                continue;
            }

            $disponible = $aplicacion->usos
                ->reduce(fn (string $saldo, PagoAplicacion $reembolso): string => bcsub($saldo, $reembolso->monto, 2), $aplicacion->monto);
            if (bccomp($disponible, '0', 2) <= 0) {
                continue;
            }

            $parcial = bccomp($monto, $disponible, 2) > 0 ? $disponible : $monto;
            $reembolsos[] = ['origen' => $aplicacion->id, 'metodo' => $aplicacion->pago->metodo_pago->value, 'saldo_favor' => $aplicacion->es_saldo_favor, 'monto' => $parcial];
            $monto = bcsub($monto, $parcial, 2);
        }

        if ($monto !== '0.00') {
            throw ValidationException::withMessages(['lineas' => 'No se pudo vincular el reembolso con los cobros originales.']);
        }

        return $reembolsos;
    }

    private function descuento(string $bruto, TipoDescuento|string|null $tipo, string $valor): string
    {
        $tipo = $tipo instanceof TipoDescuento ? $tipo : TipoDescuento::tryFrom($tipo ?? '');

        return match ($tipo) {
            TipoDescuento::Porcentaje => Montos::porcentaje($bruto, $valor),
            TipoDescuento::Importe => bccomp($valor, $bruto, 2) > 0 ? $bruto : $valor,
            null => '0.00',
        };
    }

    /** @param Collection<int, ProductoVariante> $variantes */
    private function ajustarInventario(Venta $venta, CorreccionVenta $correccion, array $cambios, Collection $variantes, TenantUser $usuario): void
    {
        $stocks = InventarioStock::query()->where('almacen_id', $venta->almacen_id)
            ->whereIn('producto_variante_id', array_column($cambios, 'variante'))->orderBy('id')->lockForUpdate()->get()->keyBy('producto_variante_id');

        foreach (collect($cambios)->sortBy(fn (array $cambio): bool => $cambio['costo'] === null) as $cambio) {
            $stock = $stocks->get($cambio['variante']);
            $variante = $variantes->get($cambio['variante']);
            $devuelve = $cambio['costo'] !== null;
            if (! $stock || ! $variante) {
                throw ValidationException::withMessages(['lineas' => 'Un producto no tiene saldo en el almacén original.']);
            }

            $previo = $stock->cantidad_actual;
            $posterior = bcsub($previo, $cambio['cantidad'], 4);
            if (! $devuelve && bccomp($posterior, $stock->cantidad_reservada, 4) < 0) {
                throw ValidationException::withMessages(['lineas' => 'Stock disponible insuficiente para '.$variante->sku.'.']);
            }

            $stock->cantidad_actual = $posterior;
            $stock->save();

            MovimientoInventario::query()->create([
                'producto_variante_id' => $variante->id, 'almacen_id' => $venta->almacen_id,
                'user_id' => $usuario->id, 'tipo_movimiento' => $devuelve ? TipoMovimientoInventario::Devolucion : TipoMovimientoInventario::SalidaVenta,
                'cantidad' => ltrim($cambio['cantidad'], '-'), 'stock_previo' => $previo, 'stock_posterior' => $posterior,
                'costo_unitario' => $cambio['costo'] ?? $variante->precio_costo,
                'costo_promedio_resultante' => $variante->precio_costo, 'referencia_tipo' => 'correccion_venta',
                'referencia_id' => $correccion->id,
            ]);
        }
    }

    public function registrarDiferencia(Venta $venta, CajaSesion $caja, SentidoPago $sentido, MetodoPago $metodo, string $monto, ?int $origen = null): void
    {
        $aplicacion = $origen ? $venta->aplicacionesPago()->findOrFail($origen) : null;
        if ($sentido === SentidoPago::Reembolso && $aplicacion?->es_saldo_favor) {
            $aplicacion->usos()->create([
                'pago_id' => $aplicacion->pago_id, 'venta_id' => null, 'es_saldo_favor' => true,
                'reserva_saldo_origen_id' => $aplicacion->reserva_saldo_origen_id, 'monto' => $monto,
            ]);

            return;
        }
        $pago = Pago::query()->create([
            'cliente_id' => $venta->cliente_id, 'caja_sesion_id' => $caja->id, 'sentido' => $sentido,
            'metodo_pago' => $metodo, 'monto' => $monto, 'estado_pago' => EstadoPago::Aprobado, 'fecha_pago' => now(),
        ]);

        $pago->aplicaciones()->create(['venta_id' => $venta->id, 'aplicacion_origen_id' => $origen, 'monto' => $monto]);
    }
}
