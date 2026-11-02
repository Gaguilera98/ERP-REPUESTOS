@props([
    'historial',
    'moneda',
])

<section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <header class="flex items-center gap-2 border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
        <x-filament::icon
            icon="heroicon-o-clock"
            class="size-5 text-primary-600 dark:text-primary-300"
        />
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
            Últimos cierres
        </h3>
    </header>

    @if ($historial->isEmpty())
        <div class="flex flex-col items-center gap-2 px-5 py-10 text-center">
            <div class="flex size-12 items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon
                    icon="heroicon-o-banknotes"
                    class="size-6"
                />
            </div>
            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">Todavía no hay cierres registrados</p>
            <p class="max-w-xs text-xs text-gray-500 dark:text-gray-400">
                Cuando cierres tu primer turno, el arqueo queda acá para auditar.
            </p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="border-b border-gray-200 bg-primary-50/70 dark:border-white/10 dark:bg-primary-400/10">
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Sucursal</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Fecha cierre</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Efectivo teórico</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Efectivo contado</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Dif. efectivo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($historial as $item)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="px-5 py-2.5 text-gray-950 dark:text-white">{{ $item->sucursal?->nombre }}</td>
                            <td class="px-5 py-2.5 text-gray-700 dark:text-gray-200">{{ $item->fecha_cierre?->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $item->monto_cierre_teorico }}</td>
                            <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $item->monto_cierre_real }}</td>
                            <td @class([
                                'px-5 py-2.5 tabular-nums font-medium',
                                'text-danger-600 dark:text-danger-400' => (float) $item->diferencia !== 0.0,
                                'text-gray-700 dark:text-gray-200' => (float) $item->diferencia === 0.0,
                            ])>
                                {{ $moneda }} {{ $item->diferencia }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
