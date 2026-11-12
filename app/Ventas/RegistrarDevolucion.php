<?php

namespace App\Ventas;

use App\Compras\CostoPromedio;
use App\Compras\Montos;
use App\Enums\EstadoCajaSesion;
use App\Enums\EstadoPago;
use App\Enums\SentidoPago;
use App\Enums\TipoComprobante;
use App\Enums\TipoMovimientoInventario;
use App\Models\CajaSesion;
use App\Models\Cliente;
use App\Models\InventarioStock;
use App\Models\MovimientoInventario;
use App\Models\NotaCreditoDevolucion;
use App\Models\Pago;
use App\Models\PagoAplicacion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\UnidadMedida;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrarDevolucion
{
    public function __construct(private CorregirVenta $correcciones, private CostoPromedio $costos) {}

    /** @return list<array{indice: int, producto: string, disponible: string, cantidad: string}> */
    public function lineasParaFormulario(Venta $venta): array
    {
        return collect($this->disponibles($venta))
            ->filter(fn (array $linea): bool => bccomp($linea['disponible'], '0', 4) > 0)
            ->map(fn (array $linea): array => [
                'indice' => $linea['indice'],
                'producto' => $linea['sku'].' · '.$linea['producto'],
                'disponible' => Montos::sinCeros($linea['disponible']).' '.$linea['unidad'],
                'cantidad' => '0',
            ])->values()->all();
    }

    public function tieneUnidadesDisponibles(Venta $venta): bool
    {
        $venta->loadMissing(['devoluciones.detalles', 'ultimaCorreccion', 'detalles']);
        $devueltas = $venta->devoluciones->flatMap->detalles->groupBy('linea_indice');
        $lineas = $venta->ultimaCorreccion?->lineas ?? $venta->detalles->sortBy('id')->values()->all();

        foreach ($lineas as $indice => $linea) {
            $cantidadDevuelta = ($devueltas->get($indice) ?? collect())
                ->reduce(fn (string $total, $detalle): string => bcadd($total, $detalle->cantidad, 4), '0.0000');
            if (bccomp($linea['cantidad'], $cantidadDevuelta, 4) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function disponibles(Venta $venta): array
    {
        $lineas = $this->correcciones->lineasVigentes($venta);
        $devueltos = $venta->devoluciones()->with('detalles')->get()->flatMap->detalles;
        $variantes = ProductoVariante::query()->with('productoMaestro')->whereKey(array_column($lineas, 'producto_variante_id'))->get()->keyBy('id');
        $originales = $venta->ultimaCorreccion ? collect() : $venta->detalles()->orderBy('id')->get();
        $unidades = UnidadMedida::query()->whereKey(array_column($lineas, 'unidad_medida_id'))->get()->keyBy('id');
        $sumaLineas = array_reduce($lineas, fn (string $suma, array $linea): string => bcadd($suma, $linea['total_linea'], 2), '0.00');
        $totalOriginal = $venta->ultimaCorreccion?->total ?? $venta->total;
        $acumulado = '0.00';
        foreach ($lineas as $indice => &$linea) {
            $previo = $acumulado;
            $acumulado = bcadd($acumulado, $linea['total_linea'], 2);
            $asignado = $sumaLineas === '0.00' ? '0.00' : bcsub(
                Montos::dividir(bcmul($totalOriginal, $acumulado, 8), $sumaLineas, 2),
                Montos::dividir(bcmul($totalOriginal, $previo, 8), $sumaLineas, 2), 2,
            );
            $anteriores = $devueltos->where('linea_indice', $indice);
            $cantidadDevuelta = $anteriores->reduce(fn (string $suma, $fila): string => bcadd($suma, $fila->cantidad, 4), '0.0000');
            $baseDevuelta = $anteriores->reduce(fn (string $suma, $fila): string => bcadd($suma, $fila->cantidad_base, 4), '0.0000');
            $importeDevuelto = $anteriores->reduce(fn (string $suma, $fila): string => bcadd($suma, $fila->importe, 2), '0.00');
            $linea = [...$linea, 'indice' => $indice, 'venta_detalle_id' => $originales->get($indice)?->id,
                'producto' => $linea['producto'] ?? $variantes->get($linea['producto_variante_id'])?->productoMaestro?->nombre ?? 'Producto',
                'sku' => $linea['sku'] ?? $variantes->get($linea['producto_variante_id'])?->sku,
                'unidad' => $unidades->get($linea['unidad_medida_id'])?->simbolo,
                'disponible' => bcsub($linea['cantidad'], $cantidadDevuelta, 4),
                'devuelto' => $cantidadDevuelta, 'base_devuelta' => $baseDevuelta, 'importe_devuelto' => $importeDevuelto, 'importe_asignado' => $asignado];
        }
        unset($linea);

        return $lineas;
    }

    /** @return array<string, mixed> */
    public function revisar(Venta $venta, array $seleccion, string $resolucion, string $motivoExcepcion, TenantUser $usuario): array
    {
        if (! in_array($resolucion, ['reembolso', 'saldo_favor'], true)) {
            $this->rechazar('Elegí una resolución para la devolución.');
        }

        return $this->revisarOperacion($venta, $seleccion, $resolucion, $motivoExcepcion, $usuario);
    }

    /** @return array<string, mixed> */
    public function revisarCambio(Venta $venta, array $seleccion, string $motivoExcepcion, TenantUser $usuario): array
    {
        return $this->revisarOperacion($venta, $seleccion, 'cambio', $motivoExcepcion, $usuario);
    }

    /** @return array<string, mixed> */
    private function revisarOperacion(Venta $venta, array $seleccion, string $resolucion, string $motivoExcepcion, TenantUser $usuario): array
    {
        $venta = Venta::query()->findOrFail($venta->id);
        abort_unless($usuario->can('Registrar:Devolucion') && $usuario->can('view', $venta), 403);
        if (! $venta->estaCompletada() || $venta->tipo_comprobante !== TipoComprobante::NotaVenta || ! $venta->entregada_en) {
            $this->rechazar('Solo se devuelven productos de notas de venta completadas y entregadas.');
        }
        $politica = $venta->politica_devolucion_snapshot;
        if (! ($politica['acepta_devoluciones'] ?? false)) {
            $this->rechazar('La política guardada en esta venta no acepta devoluciones comerciales.');
        }
        if (! ($politica['permite_'.$resolucion] ?? false)) {
            $this->rechazar('La política guardada en esta venta no permite esta operación.');
        }
        $limite = $venta->entregada_en->copy()->setTimezone($politica['zona_horaria'])->addDays($politica['dias_devolucion'])->endOfDay();
        $fueraDePlazo = now()->greaterThan($limite);
        if ($fueraDePlazo && (! $politica['permite_excepcion'] || ! $usuario->can('Autorizar:ExcepcionDevolucion'))) {
            $this->rechazar('El plazo de devolución terminó el '.$limite->format('d/m/Y H:i').'.');
        }
        if ($fueraDePlazo && trim($motivoExcepcion) === '') {
            $this->rechazar('Escribí el motivo para autorizar la excepción al plazo.');
        }
        if ($venta->aplicacionesPago()->whereHas('pago', fn ($query) => $query->where('estado_pago', EstadoPago::Pendiente))->exists()) {
            $this->rechazar('Hay pagos pendientes. Revisalos antes de devolver.');
        }
        validator(['lineas' => $seleccion], [
            'lineas' => ['required', 'array', 'max:200'],
            'lineas.*.indice' => ['required', 'integer', 'min:0', 'distinct'],
            'lineas.*.cantidad' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ])->validate();
        $disponibles = $this->disponibles($venta);
        $unidades = UnidadMedida::query()->whereKey(array_column($disponibles, 'unidad_medida_id'))->get()->keyBy('id');
        $lineas = [];
        $total = '0.00';
        foreach ($seleccion as $fila) {
            $linea = $disponibles[(int) $fila['indice']] ?? null;
            if (! $linea) {
                $this->rechazar('Una línea ya no corresponde a esta venta.');
            }
            $cantidad = Montos::cuatro($fila['cantidad']);
            if (bccomp($cantidad, '0', 4) === 0) {
                continue;
            }
            if (bccomp($cantidad, $linea['disponible'], 4) > 0 || ! $unidades->get($linea['unidad_medida_id'])?->admiteCantidad($cantidad)) {
                $this->rechazar('La cantidad no es válida o supera lo disponible de '.$linea['sku'].'.');
            }
            $acumulado = bcadd($linea['devuelto'], $cantidad, 4);
            $importe = bcsub(Montos::dividir(bcmul($linea['importe_asignado'], $acumulado, 8), $linea['cantidad'], 2), $linea['importe_devuelto'], 2);
            $base = bcsub(Montos::dividir(bcmul($linea['cantidad_base'], $acumulado, 8), $linea['cantidad'], 4), $linea['base_devuelta'], 4);
            if (bccomp($base, '0', 4) <= 0) {
                $this->rechazar('La cantidad es demasiado pequeña para reingresar stock de '.$linea['sku'].'.');
            }
            $lineas[] = [...$linea, 'cantidad_devolver' => $cantidad,
                'base_devolver' => $base, 'importe' => $importe];
            $total = bcadd($total, $importe, 2);
        }
        if ($lineas === []) {
            $this->rechazar('Indicá al menos una cantidad a devolver.');
        }
        $saldo = $venta->saldoPendiente();
        $deuda = bccomp($saldo, $total, 2) >= 0 ? $total : $saldo;
        $resolver = bcsub($total, $deuda, 2);
        if ($resolucion === 'saldo_favor' && $resolver !== '0.00' && ! $venta->cliente_id) {
            $this->rechazar('Se necesita un cliente identificado para generar saldo a favor.');
        }
        $reembolsos = $this->correcciones->distribuirReembolso($venta, $resolver);
        if ($resolucion === 'reembolso' && collect($reembolsos)->contains(fn (array $pago): bool => ! $pago['saldo_favor'])
            && ! CajaSesion::query()->where('user_id', $usuario->id)->where('sucursal_id', $venta->sucursal_id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)->whereDate('fecha_apertura', now()->toDateString())->exists()) {
            $this->rechazar('Abrí tu caja de hoy en la sucursal de la venta para registrar el reembolso.');
        }

        return ['lineas' => $lineas, 'total' => $total, 'deuda' => $deuda, 'resolver' => $resolver,
            'resolucion' => $resolucion, 'excepcion' => $fueraDePlazo ? trim($motivoExcepcion) : null,
            'limite' => $limite->toDateTimeString(), 'pagos' => $reembolsos,
            'revision' => hash('sha256', serialize([$venta->id, $venta->ultimaCorreccion?->id, $venta->totalVigente(), $venta->cobradoNeto(), $lineas, $resolucion, $fueraDePlazo ? trim($motivoExcepcion) : null]))];
    }

    public function confirmar(Venta $venta, array $seleccion, string $resolucion, string $motivo, string $motivoExcepcion, string $revision, TenantUser $usuario): void
    {
        validator(['motivo' => trim($motivo), 'motivo_excepcion' => trim($motivoExcepcion)], [
            'motivo' => ['required', 'string', 'max:500'], 'motivo_excepcion' => ['string', 'max:500'],
        ])->validate();
        abort_unless($usuario->can('Registrar:Devolucion') && $usuario->can('view', $venta), 403);
        DB::transaction(function () use ($venta, $seleccion, $resolucion, $motivo, $motivoExcepcion, $revision, $usuario): void {
            $caja = CajaSesion::query()->where('sucursal_id', $venta->sucursal_id)->where('user_id', $usuario->id)
                ->where('estado_sesion', EstadoCajaSesion::Abierta)->whereDate('fecha_apertura', now()->toDateString())->lockForUpdate()->first();
            $venta = Venta::query()->lockForUpdate()->findOrFail($venta->id);
            if ($venta->cliente_id) {
                Cliente::query()->lockForUpdate()->findOrFail($venta->cliente_id);
            }
            $resumen = $this->revisar($venta, $seleccion, $resolucion, $motivoExcepcion, $usuario);
            if (! hash_equals($resumen['revision'], $revision)) {
                $this->rechazar('La venta o sus movimientos cambiaron. Volvé a revisar la devolución.');
            }
            $mueveCaja = $resolucion === 'reembolso' && collect($resumen['pagos'])->contains(fn (array $pago): bool => ! $pago['saldo_favor']);
            if ($mueveCaja && ! $caja) {
                $this->rechazar('Abrí tu caja de hoy en la sucursal de la venta para registrar el reembolso.');
            }
            $this->guardar($venta, $resumen, $motivo, $usuario, $mueveCaja ? $caja : null);
        }, attempts: 3);
    }

    /**
     * Persiste una revisión vigente dentro de la transacción que bloqueó la venta.
     *
     * @param  array<string, mixed>  $resumen
     */
    public function guardar(Venta $venta, array $resumen, string $motivo, TenantUser $usuario, ?CajaSesion $caja, ?int $clienteSaldoId = null): NotaCreditoDevolucion
    {
        $nota = $venta->devoluciones()->create([
            'user_id' => $usuario->id, 'caja_sesion_id' => $caja?->id,
            'motivo' => trim($motivo), 'motivo_excepcion' => $resumen['excepcion'], 'revision' => $resumen['revision'],
            'resolucion' => $resumen['resolucion'], 'total' => $resumen['total'],
            'deuda_cancelada' => $resumen['deuda'], 'importe_resuelto' => $resumen['resolver'],
        ]);
        $this->reingresar($venta, $nota, $resumen['lineas'], $usuario);

        foreach ($resumen['pagos'] as $parte) {
            $origen = PagoAplicacion::query()->with('pago')->lockForUpdate()->findOrFail($parte['origen']);
            $vinculo = ['nota_credito_id' => $nota->id, 'aplicacion_origen_id' => $origen->id, 'monto' => $parte['monto']];

            if ($resumen['resolucion'] === 'saldo_favor' || $parte['saldo_favor']) {
                $origen->pago->aplicaciones()->create([
                    ...$vinculo, 'venta_id' => null, 'es_saldo_favor' => true,
                    'reserva_saldo_origen_id' => $origen->reserva_saldo_origen_id,
                    'cliente_saldo_id' => $clienteSaldoId ?? $origen->cliente_saldo_id,
                ]);
            } else {
                $pago = Pago::query()->create([
                    'cliente_id' => $venta->cliente_id, 'caja_sesion_id' => $caja->id, 'sentido' => SentidoPago::Reembolso,
                    'metodo_pago' => $origen->pago->metodo_pago, 'monto' => $parte['monto'],
                    'estado_pago' => EstadoPago::Aprobado, 'fecha_pago' => now(), 'registrado_por_id' => $usuario->id, 'motivo' => trim($motivo),
                ]);
                $pago->aplicaciones()->create([...$vinculo, 'venta_id' => $venta->id]);
            }
        }

        return $nota;
    }

    /** @param list<array<string,mixed>> $lineas */
    private function reingresar(Venta $venta, NotaCreditoDevolucion $nota, array $lineas, TenantUser $usuario): void
    {
        $variantes = ProductoVariante::query()->whereKey(array_column($lineas, 'producto_variante_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $stocks = InventarioStock::query()->whereIn('producto_variante_id', $variantes->keys())->orderBy('id')->lockForUpdate()->get()->groupBy('producto_variante_id');
        foreach ($lineas as $linea) {
            $variante = $variantes->get($linea['producto_variante_id']);
            $saldos = $stocks->get($variante->id);
            $stock = $saldos?->firstWhere('almacen_id', $venta->almacen_id);
            if (! $stock) {
                $this->rechazar('Falta el saldo de inventario del almacén original.');
            }
            $totalStock = $saldos->reduce(fn (string $suma, InventarioStock $fila): string => bcadd($suma, $fila->cantidad_actual, 4), '0.0000');
            $previo = $stock->cantidad_actual;
            $promedio = $this->costos->resultante($variante->precio_costo, $totalStock, $linea['precio_costo_snapshot'], $linea['base_devolver']);
            $stock->cantidad_actual = bcadd($previo, $linea['base_devolver'], 4);
            $stock->save();
            $variante->precio_costo = $promedio;
            $variante->save();
            $nota->detalles()->create([
                'venta_detalle_id' => $linea['venta_detalle_id'], 'linea_indice' => $linea['indice'],
                'producto_variante_id' => $variante->id, 'cantidad' => $linea['cantidad_devolver'],
                'cantidad_base' => $linea['base_devolver'], 'importe' => $linea['importe'], 'linea_snapshot' => $linea,
            ]);
            MovimientoInventario::query()->create([
                'producto_variante_id' => $variante->id, 'almacen_id' => $venta->almacen_id, 'user_id' => $usuario->id,
                'tipo_movimiento' => TipoMovimientoInventario::Devolucion, 'cantidad' => $linea['base_devolver'],
                'stock_previo' => $previo, 'stock_posterior' => $stock->cantidad_actual,
                'costo_unitario' => $linea['precio_costo_snapshot'], 'costo_promedio_resultante' => $promedio,
                'referencia_tipo' => 'nota_credito_devolucion', 'referencia_id' => $nota->id,
            ]);
        }
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['devolucion' => $mensaje]);
    }
}
