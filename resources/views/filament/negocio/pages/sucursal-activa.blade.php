@if ($puedeCambiar)
    <x-filament::dropdown :menu="false" :shift="true" :teleport="true" placement="bottom-end" width="sucursal-selector-panel">
        <x-slot name="trigger">
            <button
                type="button"
                class="sucursal-selector-trigger"
                aria-label="{{ $nombre ? 'Cambiar sucursal. Activa: '.$nombre : 'Elegir sucursal' }}"
            >
                <x-filament::icon icon="heroicon-o-building-storefront" class="size-5 shrink-0" />
                <span class="hidden max-w-40 truncate sm:block">{{ $nombre ?? 'Elegir sucursal' }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="sucursal-selector-chevron size-4 shrink-0" />
            </button>
        </x-slot>

        <div
            class="p-2"
            x-data="{
                busqueda: '',
                nombres: @js($sucursales->pluck('nombre')->all()),
                normalizar(texto) {
                    return texto.trim().toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                },
                coincide(nombre) {
                    return this.normalizar(nombre).includes(this.normalizar(this.busqueda))
                },
            }"
        >
            <p class="px-3 pt-2 pb-1 text-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400">
                {{ $nombre ? 'Sucursal activa' : 'Elegí una sucursal' }}
            </p>

            @if ($sucursales->count() > 6)
                <div class="px-2 py-2">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input
                            type="search"
                            x-model="busqueda"
                            placeholder="Buscar sucursal"
                            aria-label="Buscar sucursal"
                            autocomplete="off"
                        />
                    </x-filament::input.wrapper>
                </div>
            @endif

            <ul class="grid max-h-[min(18rem,50dvh)] gap-0.5 overflow-y-auto overscroll-contain" aria-label="Sucursales disponibles">
                @foreach ($sucursales as $sucursal)
                    @php
                        $activa = $sucursal->id === $sucursalId;
                        $almacen = $sucursal->asignaciones->first()?->almacen?->nombre;
                    @endphp
                    <li x-show="coincide(@js($sucursal->nombre))">
                        <form method="POST" action="{{ route('filament.negocio.sucursal-activa', ['sucursal' => $sucursal->id]) }}">
                            @csrf
                            <button
                                type="submit"
                                @disabled($activa)
                                @class(['sucursal-selector-opcion', 'sucursal-selector-seleccionada' => $activa])
                                @if ($activa) aria-current="true" @endif
                            >
                                <x-filament::icon icon="heroicon-o-building-storefront" class="size-5 shrink-0" />
                                <span class="grid min-w-0 flex-1 gap-0.5">
                                    <span class="truncate text-sm font-semibold">{{ $sucursal->nombre }}</span>
                                    @if ($almacen)
                                        <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $almacen }}</span>
                                    @endif
                                </span>
                                @if ($activa)
                                    <x-filament::icon icon="heroicon-m-check" class="size-5 shrink-0" />
                                @endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <p x-cloak x-show="! nombres.some(nombre => coincide(nombre))" class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                No encontramos esa sucursal.
            </p>

            @if ($urlAdministrar)
                <div class="mt-2 border-t border-gray-200 pt-2 dark:border-white/10">
                    <a href="{{ $urlAdministrar }}" class="sucursal-selector-opcion text-sm font-medium">
                        <x-filament::icon icon="heroicon-o-cog-6-tooth" class="size-5 shrink-0" />
                        Administrar sucursales
                    </a>
                </div>
            @endif
        </div>
    </x-filament::dropdown>
@elseif ($urlElegir)
    <a href="{{ $urlElegir }}" class="sucursal-selector-trigger">
        <x-filament::icon icon="heroicon-o-building-storefront" class="size-5 shrink-0" />
        <span class="truncate">Elegir sucursal</span>
    </a>
@elseif ($nombre)
    <span class="sucursal-selector-trigger" title="{{ $nombre }}">
        <x-filament::icon icon="heroicon-o-building-storefront" class="size-5 shrink-0" />
        <span class="max-w-24 truncate sm:max-w-40">{{ $nombre }}</span>
    </span>
@endif
