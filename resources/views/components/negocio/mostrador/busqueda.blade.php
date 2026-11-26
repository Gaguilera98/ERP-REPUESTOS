@props([
    'panel',
    'busqueda' => '',
    'resultadosBusqueda' => [],
    'indiceResultado' => 0,
    'totalSugerencias' => 0,
    'moneda' => 'BOB',
])

@php
    $texto = trim((string) $busqueda);
    $mostrarLista = $texto !== '';
@endphp

<section class="{{ $panel }} min-w-0 p-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="min-w-0">
            <div class="font-bold text-gray-950 dark:text-white">Buscar productos</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Buscá por código común, SKU, barras o nombre. Si hay varias opciones del código común, seleccioná la variante.</div>
        </div>

    </div>
    <div class="flex flex-col gap-2 sm:flex-row">
        <div class="negocio-field flex min-w-0 flex-1 items-center gap-2">
            <button
                type="button"
                wire:click="buscarYAgregar"
                class="shrink-0 text-warning-500 hover:text-warning-400"
                title="Agregar"
            >
                <x-filament::icon
                    icon="heroicon-m-magnifying-glass"
                    wire:loading.remove
                    wire:target="busqueda,buscarYAgregar"
                    class="size-4"
                />
                <x-negocio.spinner target="busqueda,buscarYAgregar" />
            </button>
            <input
                type="text"
                wire:model.live.debounce.500ms="busqueda"
                wire:keydown.enter.prevent="buscarYAgregar"
                wire:keydown.arrow-down.prevent="bajarResultado"
                wire:keydown.arrow-up.prevent="subirResultado"
                wire:keydown.escape.prevent="limpiarBusqueda"
                placeholder="Código común, SKU, barras o nombre"
                autocomplete="off"
                autofocus
                class="negocio-field-control"
            />
        </div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
        <x-filament::button type="button" size="xs" color="gray" icon="heroicon-m-funnel" wire:click="abrirBusquedaAvanzada">
            Búsqueda avanzada
        </x-filament::button>
        <x-filament::button type="button" size="xs" color="gray" icon="heroicon-m-bolt" wire:click="abrirFlashNuevo">
            Entrada express
        </x-filament::button>
    </div>

    @if ($mostrarLista)
        <div class="negocio-inset mt-3 overflow-hidden" wire:loading.class="opacity-60" wire:target="busqueda">
            @if ($resultadosBusqueda !== [])
                <div class="hidden grid-cols-[minmax(0,1fr)_6rem_5.5rem_6.5rem] gap-2 bg-gray-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-gray-500 sm:grid dark:bg-white/5 dark:text-gray-400">
                    <span>Producto</span>
                    <span class="text-right">Stock</span>
                    <span class="text-right">Precio</span>
                    <span class="sr-only">Acción</span>
                </div>
            @endif
            @forelse ($resultadosBusqueda as $i => $resultado)
                @php
                    $sinStock = (bool) ($resultado['sin_stock'] ?? false);
                    $stockTxt = $sinStock
                        ? '0'
                        : \App\Compras\Montos::sinCeros((string) $resultado['disponible']);
                    $unidadSimbolo = (string) ($resultado['unidad_simbolo'] ?? '');
                @endphp
                <div
                    wire:key="producto-{{ $resultado['id'] }}"
                    class="grid grid-cols-1 items-center gap-2 px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_6rem_5.5rem_6.5rem] {{ $i === $indiceResultado ? 'bg-primary-50 dark:bg-primary-400/10' : '' }}"
                >
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ filled($resultado['codigo_repuesto'] ?? null) ? $resultado['codigo_repuesto'] : $resultado['sku'] }}</div>
                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $resultado['nombre'] }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            @if (filled($resultado['condicion'] ?? null))
                                <x-filament::badge color="gray">{{ $resultado['condicion'] }}</x-filament::badge>
                            @endif
                            @if (filled($resultado['codigo_repuesto'] ?? null))
                                <span class="text-[11px] text-gray-500 dark:text-gray-400">SKU interno: {{ $resultado['sku'] }}</span>
                            @endif
                        </div>
                        @if (($resultado['ficha'] ?? '') !== '')
                            <div class="truncate text-[11px] text-gray-400">{{ $resultado['ficha'] }}</div>
                        @endif
                        @if ($sinStock)
                            <div class="mt-0.5 text-[11px] font-semibold text-amber-600 dark:text-amber-300">Sin stock · Seleccionar abre express</div>
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-3 sm:contents">
                        <div class="text-xs tabular-nums sm:text-right {{ $sinStock ? 'font-semibold text-amber-600 dark:text-amber-300' : 'font-semibold text-gray-950 dark:text-white' }}">
                            <span class="sm:hidden text-gray-400">Stock </span>{{ $stockTxt }}@if ($unidadSimbolo !== '') <span class="font-normal text-gray-400">{{ $unidadSimbolo }}</span>@endif
                        </div>
                        <div class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-200 sm:text-right">
                            <div>{{ number_format((float) $resultado['precio'], 2, '.', ',') }}</div>
                            @if (($resultado['nota_precio'] ?? '') !== '')
                                <div class="text-[10px] font-normal {{ $resultado['nota_precio'] === 'sin precio mayor' ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $resultado['nota_precio'] }}</div>
                            @endif
                        </div>
                        <div class="flex sm:justify-end">
                            <x-filament::button type="button" size="xs" color="gray" wire:click="seleccionarResultado({{ $resultado['id'] }})">
                                Seleccionar
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="flex flex-wrap items-center justify-between gap-3 px-3 py-3 text-sm text-gray-500 dark:text-gray-400">
                    <span class="min-w-0 break-words">Nada con “{{ $texto }}”.</span>
                    <button type="button" wire:click="expressDesdeBusqueda" class="font-semibold text-warning-600 hover:underline">
                        Entrada express
                    </button>
                </div>
            @endforelse

            @if ($resultadosBusqueda !== [])
                <div class="negocio-hairline flex items-center justify-between gap-3 border-t px-3 py-2">
                    <span class="text-[11px] text-gray-400">↑↓ elegir · Esc limpiar</span>
                    <button type="button" wire:click="abrirBusquedaAvanzada" class="text-xs font-semibold text-warning-600 hover:underline">
                        {{ $totalSugerencias > count($resultadosBusqueda) ? 'Ver todos ('.$totalSugerencias.')' : 'Ver todos' }}
                    </button>
                </div>
            @endif
        </div>
    @else
        <div class="mt-3 flex flex-wrap gap-3 text-[11px] text-gray-500 dark:text-gray-400">
            <span><kbd class="negocio-hairline rounded border px-1">Enter</kbd> agregar</span>
            <span><kbd class="negocio-hairline rounded border px-1">↑↓</kbd> elegir</span>
        </div>
    @endif
</section>
