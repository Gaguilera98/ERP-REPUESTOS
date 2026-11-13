@use(App\Compras\Montos)

<div class="flex flex-col gap-4">
    <x-filament::section>
        <x-slot name="heading">{{ $traslado->origen?->nombre }} → {{ $traslado->destino?->nombre }}</x-slot>
        <x-slot name="description">{{ $traslado->created_at->format('d/m/Y H:i') }} · Registrado por {{ $traslado->usuario?->name }}</x-slot>

        <x-filament::badge color="success" class="w-fit">Completado</x-filament::badge>
        @if (filled($traslado->observacion))
            <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $traslado->observacion }}</p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Productos trasladados</x-slot>
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr><th scope="col" class="p-2">Producto</th><th scope="col" class="p-2 text-right">Cantidad</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($traslado->detalles as $detalle)
                    <tr>
                        <th scope="row" class="p-2 font-medium">
                            {{ $detalle->variante?->productoMaestro?->nombre }}
                            <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $detalle->variante?->sku }}</span>
                        </th>
                        <td class="p-2 text-right tabular-nums">{{ Montos::sinCeros($detalle->cantidad) }} {{ $detalle->variante?->productoMaestro?->unidadBase?->simbolo }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Movimientos del kardex</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th scope="col" class="p-2">SKU</th>
                        <th scope="col" class="p-2">Almacén</th>
                        <th scope="col" class="p-2">Movimiento</th>
                        <th scope="col" class="p-2 text-right">Cantidad</th>
                        <th scope="col" class="p-2 text-right">Stock resultante</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($movimientos as $movimiento)
                        <tr>
                            <th scope="row" class="p-2 font-medium">{{ $movimiento->variante?->sku }}</th>
                            <td class="p-2">{{ $movimiento->almacen?->nombre }}</td>
                            <td class="p-2">
                                <x-filament::badge class="w-fit" :color="$movimiento->tipo_movimiento === \App\Enums\TipoMovimientoInventario::TrasladoSalida ? 'warning' : 'success'">
                                    {{ $movimiento->tipo_movimiento->etiqueta() }}
                                </x-filament::badge>
                            </td>
                            <td class="p-2 text-right tabular-nums">{{ bccomp($movimiento->stock_posterior, $movimiento->stock_previo, 4) < 0 ? '−' : '+' }}{{ Montos::sinCeros($movimiento->cantidad) }}</td>
                            <td class="p-2 text-right tabular-nums">{{ Montos::sinCeros($movimiento->stock_posterior) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-2 text-gray-500 dark:text-gray-400">No hay movimientos vinculados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
