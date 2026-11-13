<div class="flex flex-col gap-4">
    @if (blank($this->data['almacen_id'] ?? null))
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Elegí el almacén para empezar a armar la lista.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Agregar productos</x-slot>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1">
                    <label for="conteo-busqueda" class="text-sm font-medium">Buscar producto</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                        <x-filament::input id="conteo-busqueda" wire:model.live.debounce.300ms="busqueda" type="search"
                            placeholder="Nombre, SKU o código de barras" x-on:keydown.enter.prevent />
                    </x-filament::input.wrapper>
                </div>
                <div class="flex flex-col gap-1">
                    <label for="conteo-categoria" class="text-sm font-medium">Categoría</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="conteo-categoria" wire:model.live="categoria">
                            <option value="">Todas las categorías</option>
                            @foreach ($this->categorias as $id => $nombre)
                                <option value="{{ $id }}">{{ $nombre }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            @if ($this->resultados !== [])
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-filament::button type="button" wire:click="agregarProductos" wire:loading.attr="disabled" size="sm">
                        Agregar seleccionados
                    </x-filament::button>
                    <x-filament::button type="button" wire:click="agregarProductos(true)" wire:loading.attr="disabled" size="sm" color="gray">
                        Agregar los {{ count($this->resultados) }} visibles
                    </x-filament::button>
                </div>
                <div class="mt-3 grid max-h-80 gap-2 overflow-y-auto lg:grid-cols-2">
                    @foreach ($this->resultados as $producto)
                        <label wire:key="resultado-conteo-{{ $producto['id'] }}"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-3 py-2.5 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                            <x-filament::input.checkbox wire:model="seleccion" value="{{ $producto['id'] }}" />
                            <span class="flex min-w-0 flex-1 flex-col gap-1 text-sm">
                                <span class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                    <span class="font-medium">{{ $producto['nombre'] }}</span>
                                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                        En sistema <strong class="font-semibold text-gray-900 dark:text-white">{{ $producto['stock'] }} {{ $producto['unidad'] }}</strong>
                                    </span>
                                </span>
                                <span class="break-words text-xs text-gray-500 dark:text-gray-400">
                                    {{ implode(' · ', array_filter([$producto['sku'], $producto['marca'], $producto['categoria']])) }}
                                </span>
                                @if (filled($producto['ficha']))
                                    <span class="break-words text-xs text-gray-500 dark:text-gray-400">{{ $producto['ficha'] }}</span>
                                @endif
                                @if (filled($producto['ubicacion']))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Ubicación: {{ $producto['ubicacion'] }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @if (count($this->resultados) === 25)
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Se muestran hasta 25 resultados. Al agregarlos, aparecen los siguientes.</p>
                @endif
            @elseif (filled($this->busqueda) || filled($this->categoria))
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No hay más productos para agregar con esos filtros.</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cantidades contadas</x-slot>
            <x-slot name="description">Incluí las piezas apartadas que siguen en el almacén. Escribí 0 si no encontraste ninguna. Las filas vacías se omiten al guardar.</x-slot>

            <div x-data="{
                get contados() {
                    return Object.values(this.$wire.data.cantidades ?? {}).filter(cantidad => cantidad !== null && cantidad !== '').length
                },
                siguiente(event) {
                    const campos = Array.from(this.$el.querySelectorAll('[data-cantidad-conteo]'))
                    const siguiente = campos[campos.indexOf(event.target) + 1]
                    if (siguiente) {
                        siguiente.focus()
                        siguiente.select()
                    }
                }
            }">
                @error('data.cantidades')
                    <p role="alert" class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror

                @if ($this->filas === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">Los productos que agregues aparecerán aquí.</p>
                @else
                    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
                        <span x-text="contados"></span> de {{ count($this->filas) }} productos contados
                    </p>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-2 py-2">Producto</th>
                                <th scope="col" class="w-28 px-2 py-2 sm:w-40">Contado</th>
                                <th scope="col" class="w-10"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($this->filas as $producto)
                                <tr wire:key="fila-conteo-{{ $producto['id'] }}">
                                    <th scope="row" class="break-words px-2 py-2 font-medium">
                                        {{ $producto['nombre'] }}
                                        <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $producto['sku'] }} · {{ $producto['unidad'] }}</span>
                                    </th>
                                    <td class="px-2 py-2">
                                        <x-filament::input.wrapper :valid="! $errors->has('data.cantidades.'.$producto['id'])">
                                            <x-filament::input type="text" inputmode="decimal" autocomplete="off" maxlength="15"
                                                wire:model="data.cantidades.{{ $producto['id'] }}" data-cantidad-conteo
                                                x-on:keydown.enter.prevent="siguiente($event)"
                                                aria-label="Cantidad contada de {{ $producto['sku'] }}" placeholder="—" />
                                        </x-filament::input.wrapper>
                                        @error('data.cantidades.'.$producto['id'])
                                            <p role="alert" class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="py-2">
                                        <x-filament::icon-button type="button" icon="heroicon-o-x-mark" color="gray"
                                            label="Quitar {{ $producto['sku'] }}" wire:click="quitarProducto({{ $producto['id'] }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Al guardar, el almacén queda bloqueado para movimientos hasta que se cierre el conteo.</p>
        </x-filament::section>
    @endif
</div>
