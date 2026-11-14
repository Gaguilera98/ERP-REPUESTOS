@use(App\Compras\Montos)
@use(App\Enums\EstadoOrdenCompra)
@use(App\Enums\MonedaLineaCompra)
@use(App\Filament\Negocio\Resources\OrdenCompras\Schemas\OrdenCompraForm)

<div>
    <table role="table" class="block w-full text-sm md:table">
        <thead class="sr-only bg-gray-50 text-gray-600 md:not-sr-only dark:bg-white/5 dark:text-gray-300">
            <tr>
                <th scope="col" class="rounded-s-lg px-3 py-2 text-left font-medium">Producto</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Cantidad</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Costo unitario</th>
                <th scope="col" class="rounded-e-lg px-3 py-2 text-right font-medium">Subtotal</th>
            </tr>
        </thead>
        <tbody role="rowgroup" class="block divide-y divide-gray-200 md:table-row-group dark:divide-white/10">
            @forelse ($compra->detalles as $detalle)
                @php
                    $unidad = $detalle->unidad?->simbolo ?? $detalle->variante?->productoMaestro?->unidadBase?->simbolo ?? 'u';
                    $enUsd = $detalle->moneda === MonedaLineaCompra::Usd;
                    $costoOriginal = $detalle->costo_unitario_origen;
                    $costoLocal = $enUsd ? Montos::multiplicar($costoOriginal, $detalle->tipo_cambio_valor, 4) : $costoOriginal;
                @endphp
                <tr role="row" class="grid grid-cols-2 gap-x-3 gap-y-2 py-3 md:table-row">
                    <th role="rowheader" scope="row" class="col-span-2 text-left align-top font-semibold wrap-break-word md:px-3 md:py-3">
                        {{ $detalle->variante?->productoMaestro?->nombre ?? 'Producto' }}
                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $detalle->variante?->sku }}</span>
                    </th>
                    <td role="cell" class="align-top tabular-nums md:px-3 md:py-3 md:text-right">
                        <span aria-hidden="true" class="block text-xs text-gray-500 md:hidden dark:text-gray-400">Cantidad</span>
                        <span class="whitespace-nowrap">{{ Montos::sinCeros($detalle->cantidad_pedida) }} {{ $unidad }}</span>
                        @if ($compra->estado === EstadoOrdenCompra::RecibidaParcial || $compra->estado === EstadoOrdenCompra::Completada)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                @if (Montos::comparar($detalle->cantidad_recibida, $detalle->cantidad_pedida) === 0)
                                    Llegó todo
                                @else
                                    Llegaron {{ Montos::sinCeros($detalle->cantidad_recibida) }} de {{ Montos::sinCeros($detalle->cantidad_pedida) }} {{ $unidad }}.
                                @endif
                            </span>
                        @endif
                    </td>
                    <td role="cell" class="text-right align-top tabular-nums md:px-3 md:py-3">
                        <span aria-hidden="true" class="block text-xs text-gray-500 md:hidden dark:text-gray-400">Costo unitario</span>
                        <strong class="whitespace-nowrap">{{ $moneda }} {{ OrdenCompraForm::costoVisible($costoLocal) }}</strong>
                        @if ($enUsd)
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                <p>USD {{ Montos::sinCeros($costoOriginal) }} por {{ $unidad }}</p>
                                @php($cambioCompra = '1 USD = '.$moneda.' '.OrdenCompraForm::costoVisible($detalle->tipo_cambio_valor))
                                <div class="flex flex-wrap items-center justify-end gap-x-1">
                                    <span>Cambio de la compra · {{ $cambioCompra }}</span>
                                    <x-filament::icon-button
                                        type="button"
                                        color="warning"
                                        size="xs"
                                        icon="heroicon-o-information-circle"
                                        label="Sobre el tipo de cambio de esta compra"
                                        :tooltip="'Al registrar esta compra se guardó '.$cambioCompra.'. Con ese cambio se calculó el costo unitario en '.$moneda.'.'"
                                    />
                                </div>
                            </div>
                        @endif
                    </td>
                    <td role="cell" class="col-span-2 flex items-center justify-between text-right align-top tabular-nums md:table-cell md:px-3 md:py-3">
                        <span aria-hidden="true" class="text-xs text-gray-500 md:hidden dark:text-gray-400">Subtotal</span>
                        <strong class="whitespace-nowrap">{{ $moneda }} {{ $detalle->subtotal_linea }}</strong>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-3 text-gray-500 dark:text-gray-400">No hay productos en esta compra.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 border-t border-gray-200 px-3 pt-3 text-sm dark:border-white/10">
        <span class="font-medium">Total de la compra</span>
        <strong class="text-base tabular-nums">{{ $moneda }} {{ $compra->total }}</strong>
    </div>
</div>
