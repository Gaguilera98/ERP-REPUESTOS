@props([
    'mostrar' => false,
    'nombre' => '',
    'filas' => [],
    'totalMenor' => '0.00',
    'totalMayor' => '0.00',
    'antesDeDescuento' => false,
    'moneda' => '',
])

@if ($mostrar)
    <div
        class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-950/50 p-4"
        wire:click.self="cancelarEleccionPrecio"
        wire:keydown.escape.window="cancelarEleccionPrecio"
    >
        <div class="negocio-modal w-full max-w-lg" role="dialog" aria-modal="true" aria-labelledby="eleccion-precio-titulo">
            <div class="negocio-hairline border-b px-5 py-4">
                <div id="eleccion-precio-titulo" class="text-lg font-bold text-gray-950 dark:text-white">
                    {{ $nombre }} está definido como mayorista.
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Elegí el precio de esta venta.</p>
            </div>

            <div class="grid gap-3 px-5 py-4">
                @if ($filas === [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">Las piezas que agregues se cobran con la lista que elijas.</p>
                @else
                    <div class="negocio-inset divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($filas as $fila)
                            <div wire:key="eleccion-{{ $fila['sku'] }}" class="px-3 py-2.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="min-w-0 truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $fila['sku'] }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        menor {{ $fila['menor'] }} · mayor {{ $fila['mayor'] }}
                                    </span>
                                </div>
                                @if ($fila['menor'] === $fila['mayor'])
                                    <div class="mt-0.5 text-[11px] font-semibold text-amber-600 dark:text-amber-300">Sin precio mayor: se cobra {{ $fila['menor'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($antesDeDescuento)
                        <p class="text-xs text-gray-500 dark:text-gray-400">Esos importes son antes del descuento.</p>
                    @endif
                @endif

                <x-filament::button type="button" color="warning" class="w-full" autofocus wire:click="confirmarListaPrecio('mayor')">
                    Cobrar a mayor
                    @if ($filas !== [])
                        · {{ $moneda }} {{ number_format((float) $totalMayor, 2, '.', ',') }}
                    @endif
                </x-filament::button>
                <x-filament::button type="button" color="gray" class="w-full" wire:click="confirmarListaPrecio('menor')">
                    Cobrar a menor
                    @if ($filas !== [])
                        · {{ $moneda }} {{ number_format((float) $totalMenor, 2, '.', ',') }}
                    @endif
                </x-filament::button>
                <button type="button" wire:click="cancelarEleccionPrecio" class="py-1 text-sm font-semibold text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
@endif
