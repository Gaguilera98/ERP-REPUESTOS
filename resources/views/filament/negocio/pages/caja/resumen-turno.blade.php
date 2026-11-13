@props([
    'sesion',
    'arqueo',
    'moneda',
    'diferencias' => [],
    'mediosConDif' => 0,
])

<section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <header class="flex flex-wrap items-center justify-between gap-x-5 gap-y-2 border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Resumen del turno</h3>
                @if ($mediosConDif > 0)
                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">
                        {{ $mediosConDif }} {{ $mediosConDif === 1 ? 'medio descuadrado' : 'medios descuadrados' }}
                    </span>
                @endif
            </div>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Esperado por medio. Al cerrar confirmás cada uno.
            </p>
        </div>
        <p class="grid gap-0.5 sm:justify-items-end">
            <span @class([
                'inline-flex items-center gap-2 text-lg leading-tight font-bold',
                'text-emerald-700 dark:text-emerald-300' => $sesion->estaAbierta(),
                'text-amber-700 dark:text-amber-300' => ! $sesion->estaAbierta(),
            ])>
                <span aria-hidden="true" class="size-2.5 shrink-0 rounded-full bg-current"></span>
                {{ $sesion->estado_sesion->etiqueta() }}
            </span>
            <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                Apertura <time datetime="{{ $sesion->fecha_apertura->toAtomString() }}">{{ $sesion->fecha_apertura->format('d/m/Y H:i') }}</time>
            </span>
        </p>
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
                    @php $dif = $diferencias[$fila['metodo']] ?? '0.00'; @endphp
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
    @if ($arqueo['tiene_caja_chica'] ?? false)
        <div class="border-t border-gray-200 px-5 py-4 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Caja chica de este turno</h3>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs text-gray-500">Gastos</p>
                    <p class="font-semibold tabular-nums">{{ $moneda }} {{ $arqueo['gastos_caja_chica'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs text-gray-500">Vales entregados</p>
                    <p class="font-semibold tabular-nums">{{ $moneda }} {{ $arqueo['vales_entregados'] }}</p>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/5">
                    <p class="text-xs text-gray-500">Sobrantes devueltos</p>
                    <p class="font-semibold tabular-nums">{{ $moneda }} {{ $arqueo['sobrantes_caja_chica'] }}</p>
                </div>
            </div>
            @if (($arqueo['vales_pendientes'] ?? []) !== [])
                <ul class="mt-3 space-y-1 text-sm text-amber-800 dark:text-amber-200">
                    @foreach ($arqueo['vales_pendientes'] as $vale)
                        <li>Vale pendiente {{ $moneda }} {{ $vale['monto'] }} · {{ $vale['persona'] }} · {{ $vale['concepto'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</section>
