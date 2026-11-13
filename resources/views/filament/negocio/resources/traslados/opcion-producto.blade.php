<span class="traslado-opcion-producto block min-w-0 whitespace-normal py-1">
    <span class="traslado-opcion-producto-encabezado flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <span class="font-medium">{{ $nombre }}</span>
        <span class="traslado-opcion-producto-detalle text-xs text-gray-600 dark:text-gray-300">
            Disponible en origen <strong class="font-semibold">{{ $disponible }} {{ $unidad }}</strong>
        </span>
    </span>
    <span class="traslado-opcion-producto-detalle mt-1 block text-xs text-gray-600 dark:text-gray-300">
        {{ implode(' · ', array_filter([$identificacion, $categoria])) }}
        @if (filled($ubicacion))
            · Ubicación: {{ $ubicacion }}
        @endif
    </span>
    @if (filled($ficha))
        <span class="traslado-opcion-producto-detalle mt-1 block text-xs text-gray-600 dark:text-gray-300">{{ $ficha }}</span>
    @endif
</span>
