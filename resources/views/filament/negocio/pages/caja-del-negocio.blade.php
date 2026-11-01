<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <form
            wire:submit="{{ $this->tieneSesionAbierta() ? 'cerrar' : 'abrir' }}"
            class="space-y-6"
        >
            {{ $this->form }}

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <x-filament::button
                    type="submit"
                    color="{{ $this->tieneSesionAbierta() ? 'danger' : 'primary' }}"
                    size="lg"
                >
                    {{ $this->tieneSesionAbierta() ? 'Cerrar caja' : 'Abrir caja' }}
                </x-filament::button>

                @if ($this->tieneSesionAbierta())
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Contá el efectivo del cajón antes de cerrar.
                    </p>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Con el monto del cambio del cajón listo, abrí el turno.
                    </p>
                @endif
            </div>
        </form>

        @php($historial = $this->historial())

        @if ($historial->isNotEmpty())
            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <header class="border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                        Últimos cierres
                    </h3>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-start">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5">
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Sucursal</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Cierre</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Teórico</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Contado</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($historial as $sesion)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="px-5 py-2.5 text-gray-950 dark:text-white">{{ $sesion->sucursal?->nombre }}</td>
                                    <td class="px-5 py-2.5 text-gray-700 dark:text-gray-200">{{ $sesion->fecha_cierre?->format('d/m/Y H:i') }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $this->moneda() }} {{ $sesion->monto_cierre_teorico }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $this->moneda() }} {{ $sesion->monto_cierre_real }}</td>
                                    <td @class([
                                        'px-5 py-2.5 tabular-nums',
                                        'text-danger-600 dark:text-danger-400' => (float) $sesion->diferencia !== 0.0,
                                        'text-gray-700 dark:text-gray-200' => (float) $sesion->diferencia === 0.0,
                                    ])>
                                        {{ $this->moneda() }} {{ $sesion->diferencia }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
