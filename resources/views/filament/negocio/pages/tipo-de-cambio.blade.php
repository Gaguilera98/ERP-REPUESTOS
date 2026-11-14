<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Cotización comercial</x-slot>
        <x-slot name="description">Actualiza los precios Menor y Mayor que tengan referencia en USD.</x-slot>

        <div class="space-y-4">
            <p class="text-sm">
                @if ($vigente)
                    Vigente <strong class="tabular-nums">{{ $moneda }} {{ $vigente->valor }}</strong> por 1 USD
                @else
                    Todavía no hay una cotización publicada.
                @endif
            </p>

            <form wire:submit="revisar" class="space-y-3">
                <div class="max-w-xs">{{ $this->form }}</div>
                <x-filament::button type="submit" color="gray">Revisar precios</x-filament::button>
            </form>
        </div>
    </x-filament::section>

    @if ($vistaPrevia)
        <x-filament::section>
            <x-slot name="heading">Precios después del cambio</x-slot>
            <x-slot name="description">
                {{ $vistaPrevia['cantidad'] }} variantes cambiarían de precio en {{ $moneda }}.
            </x-slot>

            @php
                $hayConflictos = $vistaPrevia['conflictos'] > 0;
                $filas = $hayConflictos ? $vistaPrevia['errores'] : $vistaPrevia['cambios'];
                $totalFilas = $hayConflictos ? $vistaPrevia['conflictos'] : $vistaPrevia['cantidad'];
            @endphp

            @if ($hayConflictos)
                <div class="mb-4">
                    <x-filament::badge color="warning">{{ $vistaPrevia['conflictos'] }} variantes por corregir</x-filament::badge>
                </div>
            @endif

            @if ($filas)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th scope="col" class="py-2 pr-4 text-left">SKU</th>
                                <th scope="col" class="px-2 py-2 text-right">Menor</th>
                                <th scope="col" class="px-2 py-2 text-right">Mayor</th>
                                @if ($hayConflictos)
                                    <th scope="col" class="py-2 pl-4 text-left">Por corregir</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($filas as $fila)
                                <tr>
                                    <th scope="row" class="py-2 pr-4 text-left font-medium">{{ $fila['sku'] }}</th>
                                    <td class="px-2 py-2 text-right whitespace-nowrap tabular-nums">{{ $fila['menor_antes'] ?? '—' }} → {{ $fila['menor_despues'] ?? '—' }}</td>
                                    <td class="px-2 py-2 text-right whitespace-nowrap tabular-nums">{{ $fila['mayor_antes'] ?? '—' }} → {{ $fila['mayor_despues'] ?? '—' }}</td>
                                    @if ($hayConflictos)
                                        <td class="py-2 pl-4">{{ $fila['problema'] }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($totalFilas > count($filas))
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Se muestran {{ count($filas) }} de {{ $totalFilas }} variantes.</p>
                @endif
            @endif

            <div class="mt-4">
                <x-filament::button wire:click="publicar" :disabled="$hayConflictos">Publicar tipo de cambio</x-filament::button>
                @error('valor') <p class="mt-2 text-sm">{{ $message }}</p> @enderror
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Historial de cotizaciones</x-slot>

        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($historial as $cotizacion)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>{{ $cotizacion->created_at->format('d/m/Y H:i') }} · {{ $cotizacion->usuario?->name }}</span>
                    <strong class="tabular-nums">{{ $cotizacion->moneda_local }} {{ $cotizacion->valor }} por 1 USD</strong>
                </li>
            @empty
                <li class="text-sm text-gray-500 dark:text-gray-400">Todavía no se publicaron cotizaciones.</li>
            @endforelse
        </ul>

        {{ $historial->links() }}
    </x-filament::section>
</x-filament-panels::page>
