@if ($url)
    <x-filament::button tag="a" :href="$url" color="gray" size="sm" icon="heroicon-o-building-storefront">
        {{ $nombre ?? 'Elegir sucursal' }}
    </x-filament::button>
@elseif ($nombre)
    <x-filament::badge color="gray" icon="heroicon-o-building-storefront">{{ $nombre }}</x-filament::badge>
@endif
