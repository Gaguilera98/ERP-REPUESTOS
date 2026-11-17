<x-filament-panels::page.simple>
    @if ($this->puedeConfigurar())
        <form wire:submit="guardar">
            {{ $this->form }}
        </form>
    @else
        <x-filament::section icon="heroicon-o-information-circle">
            <x-slot name="heading">El negocio aún necesita una sucursal y un almacén</x-slot>
            <x-slot name="description">Pedile a la persona encargada que complete la configuración inicial para poder operar.</x-slot>
        </x-filament::section>
    @endif

    <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="flex justify-center">
        @csrf
        <x-filament::button type="submit" color="gray" outlined>
            Cerrar sesión
        </x-filament::button>
    </form>
</x-filament-panels::page.simple>
