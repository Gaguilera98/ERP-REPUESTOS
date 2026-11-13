<x-filament::section>
    <x-slot name="heading">Productos a trasladar</x-slot>
    <x-slot name="description">El disponible en origen ya descuenta las reservas. Al confirmar, las piezas salen del origen y entran al destino en el mismo paso.</x-slot>

    @error('data.cantidades')
        <p role="alert" class="mb-3 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    @if ($this->filas === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">Usá el buscador para agregar los productos que vas a mover.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th scope="col" class="p-2">Producto</th>
                        <th scope="col" class="p-2 text-right">Disponible en origen</th>
                        <th scope="col" class="p-2">A mover</th>
                        <th scope="col" class="p-2 text-right">Stock final en origen</th>
                        <th scope="col" class="p-2 text-right">Stock final en destino</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->filas as $fila)
                        <tr wire:key="traslado-producto-{{ $fila['id'] }}-{{ $this->data['almacen_origen_id'] }}-{{ $this->data['almacen_destino_id'] }}-{{ $fila['origen'] }}-{{ $fila['destino'] }}-{{ $fila['disponible'] }}" x-data="{
                            cantidad: $wire.entangle('data.cantidades.{{ $fila['id'] }}'),
                            get valor() {
                                const texto = String(this.cantidad ?? '').replace(',', '.')
                                return /^\d{1,10}(\.\d{1,4})?$/.test(texto) ? Number(texto) : 0
                            },
                            get excede() { return this.valor > @js((float) $fila['disponible']) },
                            formato(valor) { return new Intl.NumberFormat('es-BO', { maximumFractionDigits: 4 }).format(valor) }
                        }">
                            <th scope="row" class="p-2 font-medium">
                                {{ $fila['nombre'] }}
                                <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ implode(' · ', array_filter([$fila['sku'], $fila['marca'], $fila['unidad']])) }}</span>
                            </th>
                            <td class="p-2 text-right tabular-nums">{{ $fila['disponible'] }}</td>
                            <td class="p-2">
                                <x-filament::input.wrapper class="min-w-24">
                                    <x-filament::input type="text" inputmode="decimal" maxlength="15" x-model="cantidad"
                                        aria-label="Cantidad a mover de {{ $fila['sku'] }}" x-on:keydown.enter.prevent />
                                </x-filament::input.wrapper>
                                <p x-show="excede" x-cloak class="mt-1 text-xs text-danger-600 dark:text-danger-400">Supera lo disponible</p>
                                @error('data.cantidades.'.$fila['id'])
                                    <p role="alert" class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                                @enderror
                            </td>
                            <td class="p-2 text-right tabular-nums" x-text="excede ? '—' : formato(@js((float) $fila['origen']) - valor)">{{ $fila['origen'] }}</td>
                            <td class="p-2 text-right tabular-nums" x-text="excede ? '—' : formato(@js((float) $fila['destino']) + valor)">{{ $fila['destino'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
