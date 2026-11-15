<x-filament-panels::page>
    <x-filament::section heading="Sucursales disponibles">
        @forelse ($sucursales as $sucursal)
            <x-filament::button color="gray" icon="heroicon-o-building-storefront" wire:click="elegir({{ $sucursal->id }})">
                {{ $sucursal->nombre }}
            </x-filament::button>
        @empty
            <p>Pedile al administrador que te asigne una sucursal para poder operar.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
