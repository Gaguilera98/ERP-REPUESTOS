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
    $saldoRestituido = $venta->aplicacionesPago->where('es_saldo_favor', true)
        ->reduce(fn (string $total, $aplicacion) => $aplicacion->usos
            ->filter(fn ($uso) => $uso->venta_id === null && $uso->es_saldo_favor)
            ->reduce(fn (string $suma, $uso) => bcadd($suma, $uso->monto, 2), $total), '0.00');
    $cobrado = bcsub($cobrado, $saldoRestituido, 2);
    $pagado = bcsub($cobrado, $reembolsado, 2);
    $mostrarMovimientos = $venta->ultimaCorreccion || bccomp($reembolsado, '0', 2) > 0;
    $lineasActuales = collect($venta->ultimaCorreccion?->lineas ?? []);
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $venta->created_at?->format('d/m/Y H:i') }}</p>
        <x-filament::badge :color="$colorEstado">{{ $venta->estado->etiqueta() }}</x-filament::badge>
    </div>
    @if ($venta->anulada_en)
        <p>Anulada por {{ $venta->anuladaPor?->name ?? '—' }} el {{ $venta->anulada_en->format('d/m/Y H:i') }}.</p>
        <p>Motivo: {{ $venta->motivo_anulacion }}</p>
    @endif
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
    @if (($ampliacionesReserva ?? collect())->isNotEmpty())
        <details class="group rounded-lg border border-gray-200 dark:border-white/10">
            <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-4 py-3 text-sm font-semibold text-gray-950 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-primary-500 dark:text-white dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden">
                <x-filament::icon icon="heroicon-o-clock" class="size-5 shrink-0 text-gray-400" />
                <span class="min-w-0 flex-1">Historial de ampliaciones</span>
                <x-filament::badge color="gray">{{ $ampliacionesReserva->count() }}</x-filament::badge>
                <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180" />
            </summary>
            <div class="max-h-80 divide-y divide-gray-200 overflow-y-auto border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($ampliacionesReserva as $ampliacion)
                    <div class="space-y-3 px-4 py-4 text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <p class="min-w-0 break-words font-medium text-gray-950 dark:text-white">{{ $ampliacion->usuario_nombre }}</p>
                            <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($ampliacion->created_at)->format('d/m/Y H:i') }}</p>
                        </div>
                        <dl class="grid grid-cols-1 gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-white/5">
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Vencimiento anterior</dt>
                                <dd class="mt-1 tabular-nums text-gray-700 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($ampliacion->vencimiento_anterior)->format('d/m/Y H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Nuevo vencimiento</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-950 dark:text-white">{{ \Illuminate\Support\Carbon::parse($ampliacion->vencimiento_nuevo)->format('d/m/Y H:i') }}</dd>
                            </div>
                        </dl>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Motivo</p>
                            <p class="mt-1 whitespace-pre-line break-words text-gray-700 dark:text-gray-300">{{ $ampliacion->motivo }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif
    @if (bccomp($reembolsado, '0', 2) > 0)
        <div class="flex items-start gap-3 rounded-lg border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:border-warning-400/30 dark:bg-warning-400/10 dark:text-warning-300">
            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="mt-0.5 size-5 shrink-0" />
            <p><span class="font-semibold">Reembolso registrado · {{ $moneda }} {{ $reembolsado }}</span><br>Consultá los movimientos y sus fechas en los pagos de la venta.</p>
        </div>
    @endif
    <div class="grid gap-5 md:grid-cols-[minmax(0,2fr)_minmax(15rem,1fr)] md:items-start">
        <div class="contents md:block md:min-w-0 md:space-y-5">
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
        <section class="order-3 rounded-lg border border-primary-200 dark:border-primary-400/30">
            <div class="px-4 py-3">
                <h3 class="font-semibold text-gray-950 dark:text-white">Productos después de la corrección</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Cantidades de la venta original y después de las correcciones.</p>
            </div>
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
        </section>
    @endif

    <details @if (! $venta->ultimaCorreccion) open @endif class="order-3 rounded-lg border border-gray-200 dark:border-white/10">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold text-gray-950 dark:text-white">
            {{ $venta->ultimaCorreccion ? 'Productos de la venta original' : 'Productos' }}
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

    @if ($venta->ultimaCorreccion)
        <details class="order-3 rounded-lg border border-gray-200 dark:border-white/10">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold text-gray-950 dark:text-white">
                Historial de correcciones
                <x-filament::icon icon="heroicon-o-chevron-down" class="ms-auto size-4 text-gray-500 dark:text-gray-400" />
            </summary>
            <ul class="space-y-3 px-4 pb-4 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($venta->correcciones as $correccion)
                    <li>
                        <p class="font-medium text-gray-950 dark:text-white">{{ $correccion->created_at->format('d/m/Y H:i') }} · {{ $correccion->usuario?->name ?? '—' }}</p>
                        <p>{{ $correccion->motivo }} · Total {{ $moneda }} {{ $correccion->total }}</p>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="order-5 overflow-x-auto">
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
                            {{ $aplicacion->es_saldo_favor ? 'Saldo a favor' : ($pago?->metodo_pago?->etiqueta() ?? '—') }}
                            @if (filled($pago?->numero_referencia_banco) || filled($pago?->qr_id_transaccion_banco))
                                <p class="text-xs text-gray-500 dark:text-gray-400">Ref. {{ $pago->numero_referencia_banco ?? $pago->qr_id_transaccion_banco }}</p>
                            @endif
                        </td>
                        <td data-label="Fecha y hora" class="px-2 py-3">{{ ($aplicacion->es_saldo_favor ? $aplicacion->created_at : $pago?->fecha_pago)?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td data-label="Movimiento" class="px-2 py-3">
                            @if ($aplicacion->es_saldo_favor)
                                Saldo aplicado
                            @elseif ($pago?->sentido === SentidoPago::Reembolso)
                                <x-filament::badge color="warning">Reembolso</x-filament::badge>
                            @else
                                {{ $pago?->sentido?->etiqueta() ?? '—' }}
                            @endif
                            @if (! $aplicacion->es_saldo_favor && $pago?->caja_sesion_id)
                                <p>Sesión #{{ $pago->caja_sesion_id }}</p>
                            @endif
                            @if (filled($pago?->motivo))
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
                            @if (! $aplicacion->es_saldo_favor && $pago?->efectivo_recibido !== null)
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Recibido: {{ $moneda }} {{ $pago->efectivo_recibido }}</p>
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Cambio: {{ $moneda }} {{ $pago->cambio_entregado }}</p>
                            @endif
                            @if ($aplicacion->es_saldo_favor)
                                @foreach ($aplicacion->usos as $uso)
                                    @if ($uso->venta_id === null && $uso->es_saldo_favor)
                                        <p class="text-xs font-normal text-gray-500 dark:text-gray-400">Restituido al cliente · {{ $moneda }} {{ $uso->monto }}</p>
                                    @endif
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No hay pagos registrados para esta venta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (filled($venta->notes_internas))
        <section class="order-6 space-y-1 text-sm">
            <h3 class="font-semibold text-gray-950 dark:text-white">Observaciones</h3>
            <p class="whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $venta->notes_internas }}</p>
        </section>
    @endif

        </div>
        <aside class="contents md:block md:space-y-5 md:rounded-lg md:border md:border-primary-200 md:bg-primary-50/50 md:p-4 dark:md:border-primary-400/30 dark:md:bg-primary-400/10">
            <div class="order-1 rounded-lg border border-primary-200 bg-primary-50/50 p-4 md:border-0 md:bg-transparent md:p-0 dark:border-primary-400/30 dark:bg-primary-400/10 dark:md:bg-transparent">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $venta->ultimaCorreccion ? 'Total actual de la venta' : 'Total de la venta' }}</p>
                @if (isset($saldoPendiente) && ! $mostrarMovimientos)
                    <p class="mt-2 text-sm font-medium">Saldo pendiente: {{ $moneda }} {{ $saldoPendiente }}</p>
                @endif
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->totalVigente() }}</p>
            </div>
        <div class="order-4 space-y-5 rounded-lg border border-gray-200 p-4 md:border-0 md:p-0 dark:border-white/10">
            <h3 class="font-semibold text-gray-950 md:sr-only dark:text-white">Resumen</h3>
    <dl class="w-full space-y-2 text-sm">
        @if ($mostrarMovimientos)
            @if (bccomp($reembolsado, '0', 2) > 0)
            <div class="flex items-center justify-between gap-4">
                <dt class="text-gray-500 dark:text-gray-400">Cobrado</dt>
                <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $cobrado }}</dd>
            </div>
            <div class="flex items-center justify-between gap-4 font-semibold text-warning-600 dark:text-warning-400">
                <dt>Reembolsado</dt>
                <dd class="whitespace-nowrap tabular-nums">− {{ $moneda }} {{ $reembolsado }}</dd>
            </div>
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
        </aside>
    </div>
</div>
