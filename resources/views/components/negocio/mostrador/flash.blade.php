@props([
    'mostrarFlash' => false,
    'flashVarianteId' => null,
    'flashNombre' => '',
    'flashSku' => '',
    'flashPrecioVenta' => '',
    'flashCosto' => '',
    'flashCantidad' => '1',
    'moneda' => 'BOB',
])

@if ($mostrarFlash)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" wire:click.self="cerrarFlash">
        <div class="negocio-modal w-full max-w-lg p-5" role="dialog" aria-modal="true">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <div class="text-lg font-bold text-gray-950 dark:text-white">
                        {{ $flashVarianteId ? 'Entrada express' : 'Producto + entrada express' }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Compra flash al mismo almacén de la venta. Queda stock listo para vender.
                    </div>
                </div>
                <button type="button" wire:click="cerrarFlash" class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">✕</button>
            </div>

            <div class="grid gap-3">
                @unless ($flashVarianteId)
                    <label class="block">
                        <x-negocio.label>Nombre</x-negocio.label>
                        <x-negocio.input wire:model="flashNombre" />
                    </label>
                    <label class="block">
                        <x-negocio.label>SKU (opcional)</x-negocio.label>
                        <x-negocio.input wire:model="flashSku" />
                    </label>
                    <label class="block">
                        <x-negocio.label>Precio de venta</x-negocio.label>
                        <x-negocio.input-monto
                            :moneda="$moneda"
                            wire:model="flashPrecioVenta"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                    </label>
                @endunless
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <x-negocio.label>Costo unitario</x-negocio.label>
                        <x-negocio.input-monto
                            :moneda="$moneda"
                            wire:model="flashCosto"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                    </label>
                    <label class="block">
                        <x-negocio.label>Cantidad a ingresar</x-negocio.label>
                        <x-negocio.input wire:model="flashCantidad" inputmode="decimal" />
                    </label>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-filament::button type="button" color="gray" wire:click="cerrarFlash">Cancelar</x-filament::button>
                <x-filament::button type="button" color="warning" wire:click="ejecutarFlash">Confirmar entrada</x-filament::button>
            </div>
        </div>
    </div>
@endif
