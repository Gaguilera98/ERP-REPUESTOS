<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="font-semibold text-gray-950 dark:text-white">{{ $sesion->sucursal?->nombre }}</p>
            <p class="flex flex-col gap-x-3 text-sm text-gray-500 sm:flex-row sm:flex-wrap dark:text-gray-400">
                <span>Apertura: {{ $sesion->fecha_apertura?->format('d/m/Y H:i') }}</span>
                <span>Cierre: {{ $sesion->fecha_cierre?->format('d/m/Y H:i') ?? 'Sin cerrar' }}</span>
            </p>
        </div>
        <x-filament::badge :color="$sesion->estado_sesion->getColor()">
            {{ $sesion->estado_sesion->etiqueta() }}
        </x-filament::badge>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach (['Fondo inicial' => $arqueo['apertura'], 'Cobros del turno' => $arqueo['total_cobros'], 'Reembolsos' => $arqueo['total_reembolsos'], 'Gastos' => $arqueo['gastos_caja_chica'] ?? '0.00', 'Vales' => $arqueo['vales_entregados'] ?? '0.00', 'Sobrantes' => $arqueo['sobrantes_caja_chica'] ?? '0.00'] as $etiqueta => $monto)
            <div class="rounded-xl bg-primary-50 p-3 first:col-span-2 sm:p-4 sm:first:col-span-1 dark:bg-primary-400/10">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                <p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $monto }}</p>
            </div>
        @endforeach
    </div>
    @if (($arqueo['vales_pendientes'] ?? []) !== [])
        <ul class="space-y-1 text-sm text-amber-800 dark:text-amber-200">
            @foreach ($arqueo['vales_pendientes'] as $vale)
                <li>Vale pendiente {{ $moneda }} {{ $vale['monto'] }} · {{ $vale['persona'] }} · {{ $vale['concepto'] }}</li>
            @endforeach
        </ul>
    @endif

    <div class="overflow-x-auto">
        <table class="caja-resumen-table w-full text-start text-sm">
            <caption class="py-3 text-start font-semibold text-gray-950 dark:text-white">Arqueo por medio</caption>
            <thead class="bg-primary-50 dark:bg-primary-400/10">
                <tr class="text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-2 text-start">Medio</th>
                    <th class="px-4 py-2 text-end">Esperado</th>
                    <th class="px-4 py-2 text-end">Declarado</th>
                    <th class="px-4 py-2 text-end">Diferencia</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($arqueo['por_metodo'] as $fila)
                    @php($medio = $sesion->medios->first(fn ($medio) => $medio->metodo_pago->value === $fila['metodo']))
                    <tr class="border-b border-gray-100 text-gray-950 dark:border-white/10 dark:text-white">
                        <td class="px-4 py-2">{{ $fila['etiqueta'] }}</td>
                        <td data-label="Esperado" class="px-4 py-2 text-end tabular-nums">{{ $moneda }} {{ $fila['esperado'] }}</td>
                        <td data-label="Declarado" class="px-4 py-2 text-end tabular-nums">{{ $medio ? $moneda.' '.$medio->monto_declarado : 'Sin declarar' }}</td>
                        <td data-label="Diferencia" class="px-4 py-2 text-end tabular-nums">{{ $medio ? $moneda.' '.$medio->diferencia : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (filled($sesion->justificacion_diferencia))
        <p class="text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold">Justificación:</span> {{ $sesion->justificacion_diferencia }}</p>
    @endif

    <livewire:negocio.caja.ventas-de-sesion :sesion-id="$sesion->id" :supervision="$supervision ?? false" :key="'ventas-sesion-'.$sesion->id.'-'.(int) ($supervision ?? false)" />
</div>
