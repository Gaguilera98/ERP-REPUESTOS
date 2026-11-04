@props([
    'mostrar' => false,
    'busquedaAvanzada' => '',
    'categorias',
    'atributosCategoria',
    'filtroCategoriaId' => null,
    'filtroDisponibilidad' => 'todos',
    'filtrosAtributos' => [],
    'atributosVisibles' => [],
    'resultadosAvanzados' => [],
    'totalAvanzados' => 0,
    'paginaAvanzada' => 1,
    'ultimasPaginasAvanzadas' => 1,
    'lista' => false,
    'moneda' => 'BOB',
])

@if ($mostrar)
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-gray-950/50 p-4" wire:click.self="cerrarBusquedaAvanzada">
        <div class="negocio-modal flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden" role="dialog" aria-modal="true">
            <div class="negocio-hairline flex items-start justify-between gap-3 border-b px-5 py-4">
                <div>
                    <div class="text-lg font-bold text-gray-950 dark:text-white">Buscar productos</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Filtrá por categoría y atributos. El stock es del almacén de la venta.</div>
                </div>
                <button type="button" wire:click="cerrarBusquedaAvanzada" class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">✕</button>
            </div>

            <div class="space-y-3 overflow-y-auto px-5 py-4">
                <div class="negocio-field flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-m-magnifying-glass"
                        wire:loading.remove
                        wire:target="busquedaAvanzada,filtroCategoriaId,filtroDisponibilidad"
                        class="size-4 shrink-0 text-gray-400"
                    />
                    <x-negocio.spinner target="busquedaAvanzada,filtroCategoriaId,filtroDisponibilidad" />
                    <input
                        type="text"
                        wire:model.live.debounce.500ms="busquedaAvanzada"
                        placeholder="Nombre, SKU o código de barras"
                        autocomplete="off"
                        class="negocio-field-control"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <x-negocio.label>Categoría</x-negocio.label>
                        <x-negocio.select wire:model.live="filtroCategoriaId" loading="filtroCategoriaId">
                            <option value="">Todas</option>
                            @foreach ($categorias as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                            @endforeach
                        </x-negocio.select>
                    </label>
                    <label class="block">
                        <x-negocio.label>Disponibilidad</x-negocio.label>
                        <x-negocio.select wire:model.live="filtroDisponibilidad">
                            <option value="todos">Todos</option>
                            <option value="con_stock">Con stock en este almacén</option>
                            <option value="sin_stock">Sin stock</option>
                        </x-negocio.select>
                    </label>
                </div>

                @if ($atributosCategoria->isNotEmpty())
                    @php
                        $visibles = $atributosCategoria->whereIn('clave', $atributosVisibles)->values();
                        $disponibles = $atributosCategoria->reject(fn ($atributo) => in_array($atributo->clave, $atributosVisibles, true))->values();
                    @endphp
                    <div class="negocio-inset p-3">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="text-sm font-semibold text-gray-950 dark:text-white">Filtros de la categoría</div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400">Agregá solo los que necesitás (diámetro, sello, etc.).</div>
                            </div>
                            @if ($disponibles->isNotEmpty())
                                <x-negocio.select wire:model.live="atributoParaAgregar" class="w-auto py-1.5 text-xs">
                                    <option value="">+ Agregar filtro</option>
                                    @foreach ($disponibles as $atributo)
                                        <option value="{{ $atributo->clave }}">{{ $atributo->etiqueta }}</option>
                                    @endforeach
                                </x-negocio.select>
                            @endif
                        </div>

                        @if ($visibles->isNotEmpty())
                            <div class="mb-3 flex flex-wrap gap-1.5">
                                @foreach ($visibles as $atributo)
                                    <button
                                        type="button"
                                        wire:click="quitarFiltroAtributo('{{ $atributo->clave }}')"
                                        class="negocio-hairline inline-flex items-center gap-1 rounded-full border bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-100 dark:bg-transparent dark:text-gray-200 dark:hover:bg-white/5"
                                    >
                                        {{ $atributo->etiqueta }} <span class="text-gray-400">×</span>
                                    </button>
                                @endforeach
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($visibles as $atributo)
                                    <label class="block" wire:key="filtro-attr-{{ $atributo->id }}">
                                        <x-negocio.label>
                                            {{ $atributo->etiqueta }}
                                            @if ($atributo->unidadMedida)
                                                <span class="font-normal normal-case tracking-normal">({{ $atributo->unidadMedida->simbolo }})</span>
                                            @endif
                                        </x-negocio.label>
                                        @if ($atributo->tipo === \App\Enums\TipoAtributoCategoria::Lista)
                                            <x-negocio.select wire:model.live="filtrosAtributos.{{ $atributo->clave }}">
                                                <option value="">Todos</option>
                                                @foreach ($atributo->opciones ?? [] as $opcion)
                                                    <option value="{{ $opcion }}">{{ $opcion }}</option>
                                                @endforeach
                                            </x-negocio.select>
                                        @else
                                            <x-negocio.input
                                                wire:model.live.debounce.300ms="filtrosAtributos.{{ $atributo->clave }}"
                                                inputmode="{{ $atributo->tipo === \App\Enums\TipoAtributoCategoria::Decimal ? 'decimal' : 'text' }}"
                                            />
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <div class="negocio-inset overflow-hidden">
                    <div class="hidden grid-cols-[minmax(0,1fr)_5.5rem_88px_44px] gap-2 bg-gray-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-gray-500 sm:grid dark:bg-white/5 dark:text-gray-400">
                        <span>Producto</span>
                        <span class="text-right">Stock</span>
                        <span class="text-right">Precio</span>
                        <span></span>
                    </div>

                    @forelse ($resultadosAvanzados as $resultado)
                        <div wire:key="avanzado-{{ $resultado['id'] }}" class="grid grid-cols-1 items-center gap-2 border-t border-gray-100 px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_5.5rem_88px_44px] dark:border-white/5">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $resultado['sku'] }}</div>
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $resultado['nombre'] }}</div>
                                @if (($resultado['ficha'] ?? '') !== '')
                                    <div class="truncate text-[11px] text-gray-400">{{ $resultado['ficha'] }}</div>
                                @endif
                            </div>
                            <div class="flex items-center justify-between gap-3 sm:contents">
                                <div class="text-xs tabular-nums sm:text-right {{ ($resultado['sin_stock'] ?? false) ? 'font-semibold text-amber-600 dark:text-amber-300' : 'text-gray-700 dark:text-gray-200' }}">
                                    <span class="text-gray-400 sm:hidden">Stock </span>{{ ($resultado['sin_stock'] ?? false) ? '0' : \App\Compras\Montos::sinCeros((string) $resultado['disponible']) }}@if (($resultado['unidad_simbolo'] ?? '') !== '') <span class="font-normal text-gray-400">{{ $resultado['unidad_simbolo'] }}</span>@endif
                                </div>
                                <div class="text-xs font-semibold tabular-nums text-gray-950 dark:text-white sm:text-right">
                                    <div>{{ number_format((float) $resultado['precio'], 2, '.', ',') }}</div>
                                    @if (($resultado['nota_precio'] ?? '') !== '')
                                        <div class="text-[10px] font-normal {{ $resultado['nota_precio'] === 'sin precio mayor' ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $resultado['nota_precio'] }}</div>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <button
                                        type="button"
                                        wire:click="seleccionarResultado({{ $resultado['id'] }})"
                                        class="rounded-lg bg-warning-500 px-2 py-1 text-sm font-bold text-white hover:bg-warning-400"
                                        title="{{ ($resultado['sin_stock'] ?? false) ? 'Sin stock: abre entrada express' : 'Agregar' }}"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-3 py-6 text-sm text-gray-500 dark:border-white/5 dark:text-gray-400">
                            @if ($lista)
                                <span>No hay piezas con esos filtros.</span>
                                <button type="button" wire:click="expressDesdeBusqueda" class="font-semibold text-warning-600 hover:underline">
                                    Entrada express
                                </button>
                            @else
                                <span>Escribí un SKU, nombre o elegí un filtro para ver piezas.</span>
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="negocio-hairline flex items-center justify-between gap-3 border-t px-5 py-3">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($lista)
                        {{ $totalAvanzados }} resultado{{ $totalAvanzados === 1 ? '' : 's' }}
                        · página {{ $paginaAvanzada }} de {{ $ultimasPaginasAvanzadas }}
                    @else
                        Sin búsqueda
                    @endif
                </span>
                <div class="flex gap-2">
                    <x-filament::button type="button" color="gray" size="sm" wire:click="paginaAvanzadaAnterior" :disabled="! $lista || $paginaAvanzada <= 1">
                        Anterior
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="paginaAvanzadaSiguiente" :disabled="! $lista || $paginaAvanzada >= $ultimasPaginasAvanzadas">
                        Siguiente
                    </x-filament::button>
                </div>
            </div>
        </div>
    </div>
@endif
