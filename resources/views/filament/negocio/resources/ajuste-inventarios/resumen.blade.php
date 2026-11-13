<x-filament::section>
    <x-slot name="heading">{{ $ajuste->almacen?->nombre }}</x-slot>
    <x-slot name="description">
        {{ $ajuste->tipo->etiqueta() }} #{{ $ajuste->id }} · {{ $ajuste->created_at->format('d/m/Y H:i') }} · {{ $ajuste->usuario?->name }}
    </x-slot>

    <div x-data="{ soloDiferencias: false }" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$abierto ? 'warning' : 'success'">
                {{ $abierto ? 'En curso · Almacén bloqueado' : $ajuste->estado->etiqueta() }}
            </x-filament::badge>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                {{ $filas->count() }} {{ $filas->count() === 1 ? 'producto' : 'productos' }} ·
                {{ $diferencias }} {{ $diferencias === 1 ? 'diferencia' : 'diferencias' }}
            </span>
        </div>

        @if ($ajuste->observacion)
            <p class="text-sm">{{ $ajuste->observacion }}</p>
        @endif

        @if ($diferencias > 0 && $diferencias < $filas->count())
            <label class="flex w-fit cursor-pointer items-center gap-2 text-sm">
                <x-filament::input.checkbox x-model="soloDiferencias" />
                Solo diferencias
            </label>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th scope="col" class="px-2 py-2">Producto</th>
                        <th scope="col" class="px-2 py-2 text-right">En sistema</th>
                        <th scope="col" class="px-2 py-2 text-right">{{ $esConteo ? 'Contado' : 'Resultado' }}</th>
                        <th scope="col" class="px-2 py-2 text-right">Diferencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($filas as $fila)
                        <tr @if ($fila['sentido'] === 0) x-show="!soloDiferencias" @endif>
                            <th scope="row" class="px-2 py-2 font-medium">
                                {{ $fila['nombre'] }}
                                <span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $fila['sku'] }} · {{ $fila['unidad'] }}</span>
                            </th>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $fila['sistema'] }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $fila['fisica'] }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">
                                <x-filament::badge class="ms-auto w-fit" :color="match ($fila['sentido']) { 1 => 'success', -1 => 'warning', default => 'gray' }">
                                    {{ $fila['sentido'] === 1 ? '+' : '' }}{{ $fila['diferencia'] }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($esConteo && $ajuste->supervisor)
            <p class="text-sm text-gray-500 dark:text-gray-400">Cerrado por {{ $ajuste->supervisor->name }}</p>
        @endif
    </div>
</x-filament::section>
