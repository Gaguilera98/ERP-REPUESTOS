@props([
    'sucursales',
    'almacenes',
    'clienteSeleccionado',
    'panel',
])

<section class="{{ $panel }} p-4">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div class="text-sm font-bold text-gray-950 dark:text-white">Contexto de la venta</div>
        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">CONTADO</span>
    </div>
    <div class="grid gap-3 md:grid-cols-3">
        <label class="block">
            <x-negocio.label>Sucursal <span class="text-warning-600">*</span></x-negocio.label>
            <x-negocio.select wire:model.live="sucursalId" loading="sucursalId">
                @foreach ($sucursales as $sucursal)
                    <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                @endforeach
            </x-negocio.select>
        </label>
        <label class="block">
            <x-negocio.label>Almacén <span class="text-warning-600">*</span></x-negocio.label>
            <x-negocio.select wire:model.live="almacenId" loading="almacenId">
                @foreach ($almacenes as $almacen)
                    <option value="{{ $almacen->id }}">{{ $almacen->nombre }}</option>
                @endforeach
            </x-negocio.select>
        </label>
        <div class="block">
            <x-negocio.label>Cliente <span class="font-normal normal-case tracking-normal">(opcional)</span></x-negocio.label>
            <button
                type="button"
                wire:click="abrirCliente"
                class="negocio-field flex items-center gap-2 text-left"
            >
                <svg class="size-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.118a7.5 7.5 0 0 1 15 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.5-1.632Z" />
                </svg>
                <span class="min-w-0 flex-1 truncate {{ $clienteSeleccionado ? 'text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $clienteSeleccionado?->nombre_mostrar ?: 'Sin cliente · buscar o registrar' }}
                </span>
                @if ($clienteSeleccionado)
                    <span
                        wire:click.stop="quitarCliente"
                        class="shrink-0 px-1 text-lg leading-none text-gray-400 hover:text-gray-700 dark:hover:text-white"
                        title="Quitar cliente"
                        role="button"
                    >×</span>
                @endif
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4 shrink-0 text-warning-500" />
            </button>
        </div>
    </div>
</section>
