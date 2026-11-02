@props([
    'sesion',
    'arqueo',
    'moneda',
    'diferencias',
    'mediosConDif',
    'cierreBloqueado',
    'justificacionCompleta' => false,
])

{{-- Vista: sesión abierta — referencia mock 2 (chips + resumen + declaración) --}}
<div class="space-y-5">
    {{-- Chips de estado --}}
    <div class="flex flex-wrap items-stretch gap-3">
        <div class="flex min-w-[10rem] flex-1 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-building-storefront" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sucursal</p>
                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $sesion->sucursal?->nombre }}</p>
            </div>
        </div>

        <div class="flex min-w-[9rem] flex-1 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-wallet" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Apertura</p>
                <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['apertura'] }}</p>
            </div>
        </div>

        <div class="flex min-w-[10rem] flex-1 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-banknotes" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Teórico efectivo</p>
                <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['teorico_efectivo'] }}</p>
            </div>
        </div>

        <div class="flex items-center">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">
                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                Turno abierto · {{ $sesion->fecha_apertura?->format('H:i') }}
            </span>
        </div>
    </div>

    {{-- Resumen del turno --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="flex flex-wrap items-end justify-between gap-2 border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
            <div>
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Resumen del turno</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Esperado por medio. Al cerrar confirmás cada uno.
                </p>
            </div>
            @if ($mediosConDif > 0)
                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">
                    {{ $mediosConDif }} {{ $mediosConDif === 1 ? 'medio descuadrado' : 'medios descuadrados' }}
                </span>
            @endif
        </header>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="border-b border-gray-200 bg-primary-50/70 dark:border-white/10 dark:bg-primary-400/10">
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Medio</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Cobros</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Reembolsos</th>
                        <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Esperado</th>
                        @if ($mediosConDif > 0)
                            <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Dif.</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="px-5 py-2.5 font-medium text-gray-950 dark:text-white">Apertura</td>
                        <td class="px-5 py-2.5 tabular-nums text-gray-400 dark:text-gray-500">—</td>
                        <td class="px-5 py-2.5 tabular-nums text-gray-400 dark:text-gray-500">—</td>
                        <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['apertura'] }}</td>
                        @if ($mediosConDif > 0)
                            <td class="px-5 py-2.5 tabular-nums text-gray-400 dark:text-gray-500">—</td>
                        @endif
                    </tr>
                    @foreach ($arqueo['por_metodo'] as $fila)
                        @php($dif = $diferencias[$fila['metodo']] ?? '0.00')
                        <tr @class([
                            'border-b border-gray-100 last:border-0 dark:border-white/5',
                            'bg-danger-50/70 dark:bg-danger-500/10' => $mediosConDif > 0 && $dif !== '0.00' && str_starts_with($dif, '-'),
                            'bg-amber-50/70 dark:bg-amber-400/10' => $mediosConDif > 0 && $dif !== '0.00' && ! str_starts_with($dif, '-'),
                        ])>
                            <td class="px-5 py-2.5 font-medium text-gray-950 dark:text-white">{{ $fila['etiqueta'] }}</td>
                            <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $fila['cobros'] }}</td>
                            <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $fila['reembolsos'] }}</td>
                            <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $fila['esperado'] }}</td>
                            @if ($mediosConDif > 0)
                                <td @class([
                                    'px-5 py-2.5 tabular-nums font-medium',
                                    'text-gray-400 dark:text-gray-500' => $dif === '0.00',
                                    'text-danger-600 dark:text-danger-400' => $dif !== '0.00' && str_starts_with($dif, '-'),
                                    'text-amber-700 dark:text-amber-300' => $dif !== '0.00' && ! str_starts_with($dif, '-'),
                                ])>
                                    @if ($dif === '0.00')
                                        —
                                    @elseif (str_starts_with($dif, '-'))
                                        Faltante {{ $moneda }} {{ $dif }}
                                    @else
                                        Sobrante +{{ $moneda }} {{ $dif }}
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-gray-200 bg-primary-50/50 dark:border-white/10 dark:bg-primary-400/10">
                        <td class="px-5 py-2.5 font-semibold text-gray-950 dark:text-white">Totales cobrados</td>
                        <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['total_cobros'] }}</td>
                        <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['total_reembolsos'] }}</td>
                        <td class="px-5 py-2.5" @if ($mediosConDif > 0) colspan="2" @endif></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>

    {{-- Declaración al cerrar --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Declaración al cerrar</h3>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Prellenado con lo esperado. Si cuadra, no toques nada.
            </p>
        </header>
        <div class="space-y-4 p-5">
            @if ($mediosConDif > 0 && ! $justificacionCompleta)
                <div class="flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-400/40 dark:bg-amber-400/10 dark:text-amber-100">
                    <x-filament::icon
                        icon="heroicon-o-exclamation-triangle"
                        class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300"
                    />
                    <div>
                        <p class="font-semibold">
                            Hay diferencias en {{ $mediosConDif }} {{ $mediosConDif === 1 ? 'medio' : 'medios' }}.
                        </p>
                        <p class="mt-0.5 text-amber-800/90 dark:text-amber-200/90">
                            Describí el motivo para continuar. La justificación es obligatoria.
                        </p>
                    </div>
                </div>
            @endif

            {{ $this->form }}

            @if ($justificacionCompleta)
                <div class="flex gap-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:border-emerald-400/40 dark:bg-emerald-400/10 dark:text-emerald-100">
                    <x-filament::icon
                        icon="heroicon-o-check-circle"
                        class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-300"
                    />
                    <div>
                        <p class="font-semibold">Justificación completa: se habilita el cierre de caja.</p>
                        <p class="mt-0.5 text-emerald-800/90 dark:text-emerald-200/90">
                            Podés cerrar ahora.
                        </p>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                <x-filament::button
                    type="submit"
                    color="danger"
                    size="lg"
                    icon="heroicon-m-lock-closed"
                    :disabled="$cierreBloqueado"
                    class="min-w-40"
                >
                    Cerrar caja
                </x-filament::button>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($cierreBloqueado)
                        Completá la justificación para habilitar el cierre.
                    @elseif ($justificacionCompleta)
                        El arqueo se registrará con la diferencia y su justificación.
                    @else
                        Si algún medio no calza, justificá antes de cerrar.
                    @endif
                </p>
            </div>
        </div>
    </section>
</div>
