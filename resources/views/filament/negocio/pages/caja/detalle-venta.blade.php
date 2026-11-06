@php
    use App\Compras\Montos;
    use App\Enums\EstadoPago;
    use App\Ventas\NombreEnPantalla;
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

    <details open class="order-3 rounded-lg border border-gray-200 dark:border-white/10">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 font-semibold text-gray-950 dark:text-white">
            Productos
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
    </details>

    <div class="order-5 overflow-x-auto">
        <table class="caja-resumen-table w-full text-start text-sm">
            <caption class="py-3 text-start font-semibold text-gray-950 dark:text-white">Pagos de la venta</caption>
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
                    <tr class="border-b border-gray-100 text-gray-950 dark:border-white/10 dark:text-white">
                        <td class="px-2 py-3">
                            {{ $pago?->metodo_pago?->etiqueta() ?? '—' }}
                            @if (filled($pago?->numero_referencia_banco) || filled($pago?->qr_id_transaccion_banco))
                                <p class="text-xs text-gray-500 dark:text-gray-400">Ref. {{ $pago->numero_referencia_banco ?? $pago->qr_id_transaccion_banco }}</p>
                            @endif
                        </td>
                        <td data-label="Fecha y hora" class="px-2 py-3">{{ $pago?->fecha_pago?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td data-label="Movimiento" class="px-2 py-3">
                            {{ $pago?->sentido?->etiqueta() ?? '—' }}
                            @if ($pago?->caja_sesion_id)
                                <p>Sesión #{{ $pago->caja_sesion_id }}</p>
                            @endif
                        </td>
                        <td data-label="Estado" class="px-2 py-3">
                            <x-filament::badge :color="match ($pago?->estado_pago) { EstadoPago::Aprobado => 'success', EstadoPago::Pendiente => 'warning', EstadoPago::Expirado => 'danger', default => 'gray' }">
                                {{ $pago?->estado_pago?->etiqueta() ?? '—' }}
                            </x-filament::badge>
                        </td>
                        <td data-label="Monto aplicado" class="px-2 py-3 text-end font-semibold tabular-nums">{{ $moneda }} {{ $aplicacion->monto }}</td>
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
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total de la venta</p>
                @if (isset($saldoPendiente))
                    <p class="mt-2 text-sm font-medium">Saldo pendiente: {{ $moneda }} {{ $saldoPendiente }}</p>
                @endif
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->total }}</p>
            </div>
        <div class="order-4 space-y-5 rounded-lg border border-gray-200 p-4 md:border-0 md:p-0 dark:border-white/10">
            <h3 class="font-semibold text-gray-950 md:sr-only dark:text-white">Resumen</h3>
    <dl class="w-full space-y-2 text-sm">
        <div class="flex items-center justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt>
            <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->subtotal }}</dd>
        </div>
        <div class="flex items-center justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-400">Descuentos de líneas y venta</dt>
            <dd class="whitespace-nowrap tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $venta->descuento }}</dd>
        </div>
</dl>

        </div>
        </aside>
    </div>
</div>
