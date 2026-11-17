@php
    use App\Compras\Montos;
    use App\Enums\EstadoPago;
    use App\Enums\SentidoPago;
    use App\Ventas\NombreEnPantalla;
    $reembolsado = $venta->aplicacionesPago
        ->filter(fn ($aplicacion) => $aplicacion->pago?->estado_pago === EstadoPago::Aprobado && $aplicacion->pago?->sentido === SentidoPago::Reembolso)
        ->reduce(fn (string $total, $aplicacion) => bcadd($total, $aplicacion->monto, 2), '0.00');
    $cobrado = $venta->aplicacionesPago
        ->filter(fn ($aplicacion) => $aplicacion->pago?->estado_pago === EstadoPago::Aprobado && $aplicacion->pago?->sentido === SentidoPago::Cobro)
        ->reduce(fn (string $total, $aplicacion) => bcadd($total, $aplicacion->monto, 2), '0.00');
    $saldoRestituido = $venta->aplicacionesPago
        ->reduce(fn (string $total, $aplicacion) => $aplicacion->usos
            ->filter(fn ($uso) => $uso->venta_id === null && $uso->es_saldo_favor)
            ->reduce(fn (string $suma, $uso) => bcadd($suma, $uso->monto, 2), $total), '0.00');
    $trasladadoAlReemplazo = $venta->aplicacionesPago
        ->reduce(fn (string $total, $aplicacion) => $aplicacion->usos
            ->filter(fn ($uso) => $uso->es_compensacion_cambio)
            ->reduce(fn (string $suma, $uso) => bcadd($suma, $uso->monto, 2), $total), '0.00');
    $pagado = bcsub(bcsub(bcsub($cobrado, $saldoRestituido, 2), $reembolsado, 2), $trasladadoAlReemplazo, 2);
    $hayDevoluciones = $venta->devoluciones->isNotEmpty();
    $cambios = $venta->devoluciones->filter(fn ($nota) => $nota->ventaReemplazo !== null);
    $hayCambios = $cambios->isNotEmpty();
    $devolucionesSinCambio = $venta->devoluciones->whereNull('venta_reemplazo_id');
    $soloCambios = $hayCambios && $devolucionesSinCambio->isEmpty() && ! $venta->ultimaCorreccion;
    $sinProductosTrasCambio = $hayCambios && collect($productosTrasDevoluciones)->every(fn ($linea) => bccomp($linea['disponible'], '0', 4) === 0);
    $mostrarMovimientos = $venta->ultimaCorreccion || $hayDevoluciones || bccomp($reembolsado, '0', 2) > 0;
    $lineasActuales = collect($venta->ultimaCorreccion?->lineas ?? []);
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $venta->created_at?->format('d/m/Y H:i') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            @if ($hayDevoluciones && ! $hayCambios)
                <x-filament::badge color="warning">{{ collect($productosTrasDevoluciones)->every(fn ($linea) => bccomp($linea['disponible'], '0', 4) === 0) ? 'Devolución total' : 'Devolución parcial' }}</x-filament::badge>
            @endif
            <x-filament::badge :color="$colorEstado">{{ $venta->estado->etiqueta() }}</x-filament::badge>
            @if ($venta->tieneNotaImprimible())
                <x-filament::button
                    tag="a"
                    :href="route('filament.negocio.ventas.nota-a4', ['venta' => $venta])"
                    target="_blank"
                    rel="noopener"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-printer"
                >
                    Imprimir nota A4
                </x-filament::button>
            @endif
        </div>
    </div>
    @if ($venta->cambioOrigen?->ventaOriginal)
        <section class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-400/30 dark:bg-primary-400/10">
            <div>
                <h3 class="font-semibold text-gray-950 dark:text-white">Nota de reemplazo</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">Parte del cambio de la venta {{ $venta->cambioOrigen->ventaOriginal->codigo }}.</p>
            </div>
            {{ $accionDetalle?->getModalAction('verVentaOriginal') }}
        </section>
    @endif
    @foreach ($cambios as $cambio)
        @php
            $reemplazo = $cambio->ventaReemplazo;
            $aplicado = bcsub($cambio->total, $cambio->importe_resuelto, 2);
            $diferencia = bcsub($reemplazo->total, $cambio->total, 2);
            $reembolsoCambio = $venta->aplicacionesPago
                ->where('nota_credito_id', $cambio->id)
                ->filter(fn ($aplicacion) => $aplicacion->pago?->estado_pago === EstadoPago::Aprobado && $aplicacion->pago?->sentido === SentidoPago::Reembolso)
                ->reduce(fn (string $total, $aplicacion) => bcadd($total, $aplicacion->monto, 2), '0.00');
            $saldoCambio = bcsub($cambio->importe_resuelto, $reembolsoCambio, 2);
        @endphp
        <section class="space-y-4 rounded-lg border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-400/30 dark:bg-primary-400/10">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-950 dark:text-white">Cambio completado</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $cambio->created_at?->format('d/m/Y H:i') }} · Reemplazo {{ $reemplazo->codigo }}</p>
                </div>
                {{ $accionDetalle?->getModalAction('verReemplazo')?->__invoke(['nota' => $cambio->id]) }}
            </div>
            <dl class="grid grid-cols-1 gap-3 border-t border-primary-200 pt-3 text-sm sm:grid-cols-3 dark:border-primary-400/20">
                <div>
                    <dt class="text-gray-600 dark:text-gray-300">Aplicado de la venta original</dt>
                    <dd class="text-base font-semibold tabular-nums">{{ $moneda }} {{ $aplicado }}</dd>
                </div>
                <div>
                    <dt class="text-gray-600 dark:text-gray-300">Valor del reemplazo</dt>
                    <dd class="text-base font-semibold tabular-nums">{{ $moneda }} {{ $reemplazo->total }}</dd>
                </div>
                <div>
                    @if (bccomp($diferencia, '0', 2) > 0)
                        <dt class="text-gray-600 dark:text-gray-300">Diferencia cobrada</dt>
                        <dd class="text-base font-semibold tabular-nums">{{ $moneda }} {{ $diferencia }}</dd>
                    @elseif ($diferencia === '0.00')
                        <dt class="text-gray-600 dark:text-gray-300">Diferencia</dt>
                        <dd class="text-base font-semibold">Sin diferencia</dd>
                    @else
                        @if (bccomp($reembolsoCambio, '0', 2) > 0)
                            <dt class="text-gray-600 dark:text-gray-300">Diferencia reembolsada</dt>
                            <dd class="text-base font-semibold tabular-nums">{{ $moneda }} {{ $reembolsoCambio }}</dd>
                        @endif
                        @if (bccomp($saldoCambio, '0', 2) > 0)
                            <dt class="text-gray-600 dark:text-gray-300">Saldo a favor</dt>
                            <dd class="text-base font-semibold tabular-nums">{{ $moneda }} {{ $saldoCambio }}</dd>
                        @endif
                    @endif
                </div>
            </dl>
        </section>
    @endforeach
    @if ($venta->estado === \App\Enums\EstadoVenta::Reserva)
        <p class="text-sm text-gray-600 dark:text-gray-300">Reserva vigente hasta {{ $venta->vence_en?->format('d/m/Y H:i') }}.</p>
    @elseif ($venta->estado === \App\Enums\EstadoVenta::Vencida)
        <p class="text-sm text-gray-600 dark:text-gray-300">Reserva vencida. Los productos se liberaron y la seña quedó a favor del cliente.</p>
    @elseif ($venta->estado === \App\Enums\EstadoVenta::Anulada && isset($saldoSenaDisponible))
        <p class="text-sm text-gray-600 dark:text-gray-300">Reserva cancelada. Los productos se liberaron y la seña pasó al saldo a favor del cliente. La cancelación no registra un reembolso de dinero.</p>
    @endif
    @if (in_array($venta->estado, [\App\Enums\EstadoVenta::Reserva, \App\Enums\EstadoVenta::Vencida], true) || isset($saldoSenaDisponible))
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $venta->sena_reembolsable ? 'Seña reembolsable con autorización.' : 'Seña utilizable en otra compra. Sin reembolso según las condiciones de esta reserva.' }}</p>
    @endif
    @if (isset($saldoSenaDisponible))
        <p class="text-sm font-semibold">Saldo disponible de esta seña · {{ $moneda }} {{ $saldoSenaDisponible }}</p>
    @endif
    @if (! $soloCambios && bccomp($reembolsado, '0', 2) > 0)
        <div class="flex items-start gap-3 rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:border-warning-400/30 dark:bg-warning-400/10 dark:text-warning-300">
            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="mt-0.5 size-5 shrink-0" />
            <p><span class="font-semibold">Reembolso registrado · {{ $moneda }} {{ $reembolsado }}</span><br>Consultá los movimientos y sus fechas en los pagos de la venta.</p>
        </div>
    @endif
    <div class="grid gap-5 md:grid-cols-[minmax(0,2fr)_minmax(15rem,1fr)] md:items-start">
        <div class="contents md:block md:min-w-0 md:space-y-5">
    @if ($hayDevoluciones)
        <section class="order-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <h3 class="mb-3 font-semibold">{{ $hayCambios ? 'Productos que quedaron en esta nota' : 'Productos después de las devoluciones' }}</h3>
            <div class="overflow-x-auto">
                <table class="caja-resumen-table w-full text-sm">
                    <thead class="bg-primary-50 text-gray-500 dark:bg-primary-400/10 dark:text-gray-400"><tr><th scope="col" class="px-2 py-2 text-start">Producto</th><th scope="col" class="px-2 py-2">Vendido</th><th scope="col" class="px-2 py-2">Devuelto</th><th scope="col" class="px-2 py-2">Conserva</th></tr></thead>
                    <tbody>
                        @foreach (($productosTrasDevoluciones ?? []) as $linea)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td data-label="Producto" class="py-3">{{ $linea['producto'] }}<p class="text-xs text-gray-500">{{ $linea['sku'] }}</p></td>
                                <td data-label="Vendido" class="text-center">{{ Montos::sinCeros($linea['cantidad']) }} {{ $linea['unidad'] }}</td>
                                <td data-label="Devuelto" class="text-center text-warning-600 dark:text-warning-400">{{ Montos::sinCeros($linea['devuelto']) }} {{ $linea['unidad'] }}</td>
                                <td data-label="Conserva" class="text-center font-semibold">{{ Montos::sinCeros($linea['disponible']) }} {{ $linea['unidad'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (isset($historialVenta) && $historialVenta->isNotEmpty())
        @include('filament.negocio.pages.caja.historial-venta', ['historialVenta' => $historialVenta])
    @endif

    <dl class="order-2 grid grid-cols-2 gap-4 rounded-lg border border-gray-200 p-4 text-sm md:grid-cols-3 dark:border-white/10">
        <div class="col-span-2 sm:col-span-1">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Cliente</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $venta->cliente?->nombre_mostrar ?? 'Consumidor final' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Vendedor</dt>
            <dd class="mt-1 text-gray-950 dark:text-white">{{ $venta->usuario?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Condición</dt>
            <dd class="mt-1 text-gray-950 dark:text-white">{{ $venta->condicion->etiqueta() }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Sucursal</dt>
            <dd class="mt-1 text-gray-950 dark:text-white">{{ $venta->sucursal?->nombre ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Almacén</dt>
            <dd class="mt-1 text-gray-950 dark:text-white">{{ $venta->almacen?->nombre ?? '—' }}</dd>
        </div>
        @if (filled($venta->numero_comprobante))
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Número de comprobante</dt>
                <dd class="mt-1 text-gray-950 dark:text-white">{{ $venta->numero_comprobante }}</dd>
            </div>
        @endif
    </dl>

    @if ($venta->ultimaCorreccion)
        <details @if (! $hayDevoluciones) open @endif class="order-3 rounded-lg border border-primary-200 dark:border-primary-400/30">
            <summary class="cursor-pointer px-4 py-3 font-semibold text-gray-950 dark:text-white">{{ $hayDevoluciones ? 'Productos antes de las devoluciones' : 'Productos después de la corrección' }}</summary>
            <div class="overflow-x-auto">
                <table class="caja-resumen-table w-full text-start text-sm">
                    <thead class="bg-primary-50 text-gray-500 dark:bg-primary-400/10 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-2 py-2 text-start">Producto</th>
                            <th scope="col" class="px-2 py-2 text-end">Original</th>
                            <th scope="col" class="px-2 py-2 text-end">Actual</th>
                            <th scope="col" class="px-2 py-2 text-end">Descuento</th>
                            <th scope="col" class="px-2 py-2 text-end">Importe</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($venta->ultimaCorreccion->lineas as $linea)
                            @php
                                $originales = $venta->detalles->filter(fn ($detalle) => (int) $detalle->producto_variante_id === (int) $linea['producto_variante_id']
                                    && (int) $detalle->unidad_medida_id === (int) $linea['unidad_medida_id']
                                    && bccomp($detalle->factor_a_base, $linea['factor_a_base'], 6) === 0);
                                $actuales = $lineasActuales->filter(fn ($actual) => (int) $actual['producto_variante_id'] === (int) $linea['producto_variante_id']
                                    && (int) $actual['unidad_medida_id'] === (int) $linea['unidad_medida_id']
                                    && bccomp($actual['factor_a_base'], $linea['factor_a_base'], 6) === 0);
                                $cantidadOriginal = $originales->reduce(fn (string $total, $detalle) => bcadd($total, $detalle->cantidad, 4), '0.0000');
                            @endphp
                            <tr class="border-b border-gray-100 text-gray-950 dark:border-white/10 dark:text-white">
                                <td class="px-2 py-3">
                                    <p class="font-medium">{{ NombreEnPantalla::titulo($linea['producto']) }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $linea['sku'] }}</p>
                                </td>
                                <td data-label="Original" class="px-2 py-3 text-end tabular-nums">
                                    @if ($actuales->count() === 1)
                                        {{ Montos::sinCeros($cantidadOriginal) }} {{ $linea['unidad'] }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td data-label="Actual" class="px-2 py-3 text-end tabular-nums">{{ Montos::sinCeros($linea['cantidad']) }} {{ $linea['unidad'] }}</td>
                                <td data-label="Descuento" class="px-2 py-3 text-end tabular-nums">{{ $moneda }} {{ $linea['descuento_aplicado'] }}</td>
                                <td data-label="Importe" class="px-2 py-3 text-end font-semibold tabular-nums">{{ $moneda }} {{ $linea['total_linea'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    <details @if (! $venta->ultimaCorreccion && ! $hayDevoluciones) open @endif class="order-3 rounded-lg border border-gray-200 dark:border-white/10">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold text-gray-950 dark:text-white">
            {{ $venta->ultimaCorreccion || $hayDevoluciones ? 'Productos de la venta original' : 'Productos' }}
            <x-filament::badge color="success">{{ $venta->detalles->count() }}</x-filament::badge>
            <x-filament::icon icon="heroicon-o-chevron-down" class="ms-auto size-4 text-gray-500 dark:text-gray-400" />
        </summary>
    <div class="overflow-x-auto">
        <table class="caja-resumen-table w-full text-start text-sm">
            <caption class="sr-only">Productos de la venta</caption>
            <thead class="bg-primary-50 text-gray-500 dark:bg-primary-400/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-2 py-2 text-start">Producto</th>
                    <th scope="col" class="px-2 py-2 text-end">Cantidad</th>
                    <th scope="col" class="px-2 py-2 text-end">Precio</th>
                    <th scope="col" class="px-2 py-2 text-end">Descuento</th>
                    <th scope="col" class="px-2 py-2 text-end">Importe</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($venta->detalles as $detalle)
                    @php($unidadBase = $detalle->variante?->productoMaestro?->unidadBase?->simbolo ?? $detalle->unidad?->simbolo)
                    <tr class="border-b border-gray-100 text-gray-950 dark:border-white/10 dark:text-white">
                        <td class="px-2 py-3">
                            <p class="font-medium">{{ NombreEnPantalla::titulo($detalle->variante?->productoMaestro?->nombre) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $detalle->variante?->sku ?? '—' }}</p>
                        </td>
                        <td data-label="Cantidad" class="px-2 py-3 text-end tabular-nums">
                            {{ Montos::sinCeros($detalle->cantidad) }} {{ $detalle->unidad?->simbolo }}
                            @if (Montos::comparar($detalle->factor_a_base, '1', 6) !== 0)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ Montos::sinCeros($detalle->cantidad_base) }} {{ $unidadBase }}</p>
                            @endif
                        </td>
                        <td data-label="Precio" class="px-2 py-3 text-end tabular-nums">
                            {{ $moneda }} {{ $detalle->precio_unitario }}
                            <p class="text-xs text-gray-500 dark:text-gray-400">por {{ $unidadBase }}</p>
                        </td>
                        <td data-label="Descuento" class="px-2 py-3 text-end tabular-nums">{{ $moneda }} {{ $detalle->descuento_aplicado }}</td>
                        <td data-label="Importe" class="px-2 py-3 text-end font-semibold tabular-nums">{{ $moneda }} {{ $detalle->total_linea }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No hay productos registrados en esta venta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($venta->ultimaCorreccion)
        <div class="space-y-1 px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
            <p>Subtotal original · {{ $moneda }} {{ $venta->subtotal }}</p>
            <p>Descuentos originales · {{ $moneda }} {{ $venta->descuento }}</p>
            <p class="font-semibold text-gray-950 dark:text-white">Total original · {{ $moneda }} {{ $venta->total }}</p>
        </div>
    @endif
    </details>

    <div class="order-6 overflow-x-auto">
        <table class="caja-resumen-table w-full text-start text-sm">
            <caption class="py-3 text-start font-semibold text-gray-950 dark:text-white">Cobros y reembolsos</caption>
            <thead class="bg-primary-50 text-gray-500 dark:bg-primary-400/10 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-2 py-2 text-start">Medio</th>
                    <th scope="col" class="px-2 py-2 text-start">Fecha y hora</th>
                    <th scope="col" class="px-2 py-2 text-start">Movimiento</th>
                    <th scope="col" class="px-2 py-2 text-start">Estado</th>
                    <th scope="col" class="px-2 py-2 text-end">Monto aplicado</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($venta->aplicacionesPago as $aplicacion)
                    @php($pago = $aplicacion->pago)
                    <tr @class(['border-b border-gray-100 text-gray-950 dark:border-white/10 dark:text-white', 'bg-warning-50 dark:bg-warning-400/10' => $pago?->sentido === SentidoPago::Reembolso])>
                        <td class="px-2 py-3">
                            {{ $aplicacion->es_compensacion_cambio ? 'Cambio de producto' : ($aplicacion->es_saldo_favor ? 'Saldo a favor' : ($pago?->metodo_pago?->etiqueta() ?? '—')) }}
                            @if (! $aplicacion->es_compensacion_cambio && (filled($pago?->numero_referencia_banco) || filled($pago?->qr_id_transaccion_banco)))
                                <p class="text-xs text-gray-500 dark:text-gray-400">Ref. {{ $pago->numero_referencia_banco ?? $pago->qr_id_transaccion_banco }}</p>
                            @endif
                            @if ($urlComprobante = app(\App\Archivos\ArchivosNegocio::class)->urlTemporal($pago?->comprobante_archivo_path))
                                <p class="text-xs"><a href="{{ $urlComprobante }}" target="_blank" rel="noopener" class="font-semibold text-primary-600 underline dark:text-primary-400">Ver comprobante</a></p>
                            @endif
                        </td>
                        <td data-label="Fecha y hora" class="px-2 py-3">{{ ($aplicacion->es_saldo_favor || $aplicacion->es_compensacion_cambio ? $aplicacion->created_at : $pago?->fecha_pago)?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td data-label="Movimiento" class="px-2 py-3">
                            @if ($aplicacion->es_compensacion_cambio)
                                Valor de la pieza devuelta
                            @elseif ($aplicacion->es_saldo_favor)
                                Saldo aplicado
                            @elseif ($pago?->sentido === SentidoPago::Reembolso)
                                <x-filament::badge color="warning">Reembolso</x-filament::badge>
                            @else
                                {{ $pago?->sentido?->etiqueta() ?? '—' }}
                            @endif
                            @if (! $aplicacion->es_compensacion_cambio && ! $aplicacion->es_saldo_favor && $pago?->caja_sesion_id)
                                <p>Sesión #{{ $pago->caja_sesion_id }}</p>
                            @endif
                            @if (! $aplicacion->es_compensacion_cambio && filled($pago?->motivo))
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pago->motivo }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pago->registradoPor?->name ?? '—' }}</p>
                            @endif
                        </td>
                        <td data-label="Estado" class="px-2 py-3">
                            <x-filament::badge :color="match ($pago?->estado_pago) { EstadoPago::Aprobado => 'success', EstadoPago::Pendiente => 'warning', EstadoPago::Expirado => 'danger', default => 'gray' }">
                                {{ $pago?->estado_pago?->etiqueta() ?? '—' }}
                            </x-filament::badge>
                        </td>
                        <td data-label="Monto aplicado" class="px-2 py-3 text-end font-semibold tabular-nums">
                            {{ $pago?->sentido === SentidoPago::Reembolso ? '−' : '+' }} {{ $moneda }} {{ $aplicacion->monto }}
                            @if (! $aplicacion->es_compensacion_cambio && ! $aplicacion->es_saldo_favor && $pago?->efectivo_recibido !== null)
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Recibido: {{ $moneda }} {{ $pago->efectivo_recibido }}</p>
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Cambio: {{ $moneda }} {{ $pago->cambio_entregado }}</p>
                            @endif
                                @foreach ($aplicacion->usos as $uso)
                                    @if ($uso->venta_id === null && $uso->es_saldo_favor)
                                        <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Restituido al cliente · {{ $moneda }} {{ $uso->monto }}</p>
                                    @endif
                                @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No hay pagos registrados para esta venta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (filled($venta->notes_internas))
        <section class="order-7 space-y-1 text-sm">
            <h3 class="font-semibold text-gray-950 dark:text-white">Observaciones</h3>
            <p class="whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $venta->notes_internas }}</p>
        </section>
    @endif

        </div>
        <aside class="contents md:block md:space-y-5 md:rounded-lg md:border md:border-primary-200 md:bg-primary-50/50 md:p-4 dark:md:border-primary-400/30 dark:md:bg-primary-400/10">
            <div class="order-1 rounded-lg border border-primary-200 bg-primary-50/50 p-4 md:border-0 md:bg-transparent md:p-0 dark:border-primary-400/30 dark:bg-primary-400/10 dark:md:bg-transparent">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $hayCambios ? 'Productos de esta nota' : ($venta->ultimaCorreccion || $hayDevoluciones ? 'Total actual de la venta' : 'Total de la venta') }}</p>
                @if (isset($saldoPendiente) && ! $mostrarMovimientos)
                    <p class="mt-2 text-sm font-medium">Saldo pendiente: {{ $moneda }} {{ $saldoPendiente }}</p>
                @endif
                @if ($sinProductosTrasCambio)
                    <p class="mt-1 font-semibold text-gray-950 dark:text-white">Todas las piezas volvieron</p>
                @else
                    <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->totalVigente() }}</p>
                @endif
            </div>
        @if (! $soloCambios)
        <div class="order-4 space-y-5 rounded-lg border border-gray-200 p-4 md:border-0 md:p-0 dark:border-white/10">
            <h3 class="font-semibold text-gray-950 md:sr-only dark:text-white">Resumen</h3>
    <dl class="w-full space-y-2 text-sm">
        @if ($mostrarMovimientos)
            @if ($devolucionesSinCambio->isNotEmpty())
                <div class="flex items-center justify-between gap-4 text-warning-600 dark:text-warning-400"><dt>Valor de productos devueltos</dt><dd>{{ $moneda }} {{ Montos::dos((string) $devolucionesSinCambio->sum('total')) }}</dd></div>
            @endif
            @if (bccomp($reembolsado, '0', 2) > 0 || bccomp($saldoRestituido, '0', 2) > 0)
            <div class="flex items-center justify-between gap-4">
                <dt class="text-gray-500 dark:text-gray-400">Cobrado</dt>
                <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $cobrado }}</dd>
            </div>
            @endif
            @if (bccomp($reembolsado, '0', 2) > 0)
            <div class="flex items-center justify-between gap-4 font-semibold text-warning-600 dark:text-warning-400">
                <dt>Reembolsado</dt>
                <dd class="whitespace-nowrap tabular-nums">− {{ $moneda }} {{ $reembolsado }}</dd>
            </div>
            @endif
            @if (bccomp($saldoRestituido, '0', 2) > 0)
                <div class="flex items-center justify-between gap-4 text-warning-600 dark:text-warning-400"><dt>Restituido como saldo a favor</dt><dd class="whitespace-nowrap tabular-nums">{{ $moneda }} {{ $saldoRestituido }}</dd></div>
            @endif
            @if (isset($saldoPendiente) || bccomp($pagado, $venta->totalVigente(), 2) !== 0)
            <div class="flex items-center justify-between gap-4 font-semibold text-gray-950 dark:text-white">
                <dt>Pagado</dt>
                <dd class="whitespace-nowrap tabular-nums">{{ $moneda }} {{ $pagado }}</dd>
            </div>
            @endif
            @if (isset($saldoPendiente))
            <div class="flex items-center justify-between gap-4">
                <dt class="text-gray-500 dark:text-gray-400">Saldo pendiente</dt>
                <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $saldoPendiente }}</dd>
            </div>
            @endif
        @else
        <div class="flex items-center justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
            <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->subtotal }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-400">Descuentos de líneas y venta</dt>
            <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->descuento }}</dd>
        </div>
        @endif
</dl>

        </div>
        @endif
        </aside>
    </div>
</div>
