@props([
    'lineas',
    'moneda',
    'subtotalBruto',
    'totalesLinea' => [],
    'panel',
])

<section class="{{ $panel }} min-w-0 overflow-hidden">
    <div class="negocio-hairline flex items-center justify-between gap-3 border-b px-4 py-3">
        <div class="min-w-0">
            <div class="font-bold text-gray-950 dark:text-white">Detalle de la venta</div>
            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Ajustá cantidades antes de cobrar.</div>
        </div>
        <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-bold text-gray-700 dark:bg-white/10 dark:text-gray-200">
            {{ count($lineas) }} {{ count($lineas) === 1 ? 'ítem' : 'ítems' }}
        </span>
    </div>

    @if ($lineas === [])
        <div class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
            Todavía no hay productos. Escaneá o buscá para empezar.
        </div>
    @else
        <div class="space-y-2 p-3 md:hidden">
            @foreach ($lineas as $i => $linea)
                @php
                    $neto = $totalesLinea[$i] ?? '0.00';
                    $disponible = (string) ($linea['disponible'] ?? '0');
                    $cantidad = trim((string) ($linea['cantidad'] ?? '0')) ?: '0';
                    $stockTxt = \App\Compras\Montos::sinCeros($disponible);
                    $unidadSimbolo = (string) ($linea['unidad_simbolo'] ?? '');
                    $sinStock = \App\Compras\Montos::comparar($disponible, '0', 4) !== 1;
                    $pasaStock = preg_match('/^\d+(\.\d+)?$/', $cantidad) === 1
                        && \App\Compras\Montos::comparar($cantidad, $disponible, 4) === 1;
                @endphp
                <div wire:key="linea-m-{{ $i }}-{{ $linea['producto_variante_id'] }}" class="rounded-xl border p-3 {{ $pasaStock ? 'border-amber-500/40 bg-amber-50/70 dark:border-amber-400/20 dark:bg-amber-400/5' : 'border-gray-200 dark:border-white/10' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $linea['sku'] ?: '—' }}</div>
                            <div class="mt-0.5 truncate text-[11px] text-gray-500 dark:text-gray-400">{{ $linea['nombre'] }}</div>
                            @if ($pasaStock)
                                <span class="mt-1.5 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">Pasa el stock</span>
                            @endif
                        </div>
                        <button type="button" wire:click="quitarLinea({{ $i }})" class="shrink-0 rounded-lg p-1 text-danger-600" title="Quitar" aria-label="Quitar">
                            <x-filament::icon icon="heroicon-m-trash" class="size-4" />
                        </button>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3 text-xs">
                        <span class="tabular-nums {{ $sinStock || $pasaStock ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-200' }}">
                            Stock {{ $stockTxt }}@if ($unidadSimbolo !== '') {{ $unidadSimbolo }}@endif
                        </span>
                        <span class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                            <span class="block">{{ $moneda }} {{ number_format((float) $linea['precio_unitario'], 2, '.', ',') }}</span>
                            @if (($linea['nota_precio'] ?? '') !== '')
                                <span class="block text-[10px] font-normal {{ $linea['nota_precio'] === 'sin precio mayor' ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $linea['nota_precio'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        @include('components.negocio.mostrador.linea-cantidad')
                        <div class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ $moneda }} {{ number_format((float) $neto, 2, '.', ',') }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <table class="hidden w-full table-fixed text-xs md:table">
            <colgroup>
                <col>
                <col class="w-[4.75rem]">
                <col class="w-[6.5rem]">
                <col class="w-[6.75rem]">
                <col class="w-[4.75rem]">
                <col class="w-9">
            </colgroup>
            <thead class="bg-gray-50 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-2 text-left">Producto</th>
                    <th class="px-2 py-2 text-right">Stock</th>
                    <th class="px-2 py-2 text-right">Precio</th>
                    <th class="px-2 py-2 text-center">Cant.</th>
                    <th class="px-2 py-2 text-right">Subtotal</th>
                    <th class="px-2 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($lineas as $i => $linea)
                    @php
                        $neto = $totalesLinea[$i] ?? '0.00';
                        $disponible = (string) ($linea['disponible'] ?? '0');
                        $cantidad = trim((string) ($linea['cantidad'] ?? '0')) ?: '0';
                        $stockTxt = \App\Compras\Montos::sinCeros($disponible);
                        $unidadSimbolo = (string) ($linea['unidad_simbolo'] ?? '');
                        $sinStock = \App\Compras\Montos::comparar($disponible, '0', 4) !== 1;
                        $pasaStock = preg_match('/^\d+(\.\d+)?$/', $cantidad) === 1
                            && \App\Compras\Montos::comparar($cantidad, $disponible, 4) === 1;
                    @endphp
                    <tr wire:key="linea-d-{{ $i }}-{{ $linea['producto_variante_id'] }}" class="{{ $pasaStock ? 'bg-amber-50/70 dark:bg-amber-400/5' : '' }}">
                        <td class="px-4 py-2.5 align-middle">
                            <div class="truncate font-semibold text-gray-950 dark:text-white">{{ $linea['sku'] ?: '—' }}</div>
                            <div class="mt-0.5 truncate text-[11px] text-gray-500 dark:text-gray-400" title="{{ $linea['nombre'] }}">{{ $linea['nombre'] }}</div>
                            @if ($pasaStock)
                                <span class="mt-1 inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">Pasa el stock</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-2 py-2.5 text-right tabular-nums {{ $sinStock || $pasaStock ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-gray-950 dark:text-white' }}">
                            {{ $stockTxt }}@if ($unidadSimbolo !== '') <span class="font-normal text-gray-400"> {{ $unidadSimbolo }}</span>@endif
                        </td>
                        <td class="px-2 py-2.5 text-right tabular-nums text-gray-950 dark:text-white">
                            <div>{{ number_format((float) $linea['precio_unitario'], 2, '.', ',') }}</div>
                            @if (($linea['nota_precio'] ?? '') !== '')
                                <div class="text-[10px] font-normal {{ $linea['nota_precio'] === 'sin precio mayor' ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $linea['nota_precio'] }}</div>
                            @endif
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="flex justify-center">
                                @include('components.negocio.mostrador.linea-cantidad')
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-2 py-2.5 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ number_format((float) $neto, 2, '.', ',') }}
                        </td>
                        <td class="px-2 py-2.5 text-right">
                            <button type="button" wire:click="quitarLinea({{ $i }})" class="inline-flex rounded-md p-1 text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-400/10" title="Quitar" aria-label="Quitar">
                                <x-filament::icon icon="heroicon-m-trash" class="size-3.5" />
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="negocio-hairline flex items-center justify-between border-t px-4 py-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">Subtotal de líneas</span>
            <span class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ number_format((float) $subtotalBruto, 2, '.', ',') }}</span>
        </div>
    @endif
</section>
