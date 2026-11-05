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
    @if ($sesion->estado_sesion === \App\Enums\EstadoCajaSesion::PendienteArqueo)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
            <p class="font-semibold">Estás contando la sesión #{{ $sesion->id }} del {{ $sesion->fecha_apertura?->format('d/m/Y') }}</p>
            <p class="text-sm">Este turno es de un día anterior. El cierre corresponde a esa sesión, no a la caja de hoy.</p>
        </div>
    @endif
    <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
        <p class="font-semibold text-gray-950 dark:text-white">Sesión #{{ $sesion->id }} · {{ auth('tenant')->user()?->name }}</p>
        <p>Apertura <time datetime="{{ $sesion->fecha_apertura->toAtomString() }}">{{ $sesion->fecha_apertura->format('d/m/Y H:i') }}</time></p>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-building-storefront" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sucursal</p>
                <p class="break-words text-sm font-semibold text-gray-950 dark:text-white">{{ $sesion->sucursal?->nombre }}</p>
            </div>
        </div>
        <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-wallet" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Fondo de apertura</p>
                <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['apertura'] }}</p>
            </div>
        </div>
        <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-receipt-percent" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Cobrado en esta sesión</p>
                <p class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $arqueo['total_cobros'] }}</p>
            </div>
        </div>
        <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-emerald-600/40 dark:bg-gray-900 dark:ring-emerald-400/50">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon icon="heroicon-o-banknotes" class="size-5" />
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Efectivo esperado en cajón</p>
                <p class="text-base font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ $moneda }} {{ $arqueo['teorico_efectivo'] }}</p>
            </div>
        </div>
    </div>
    {{-- Resumen del turno --}}
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
    </section>

    {{-- Declaración al cerrar --}}
    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Cerrar esta sesión</h3>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Revisá lo esperado y declar&aacute; lo contado para cada medio.
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

            @php
                $declaracionesCompletas = collect($arqueo['por_metodo'])->every(function (array $fila): bool {
                    $valor = $this->data['medios_declarados'][$fila['metodo']] ?? null;

                    return is_numeric($valor) && (float) $valor >= 0;
                });
            @endphp
            @if ($declaracionesCompletas && $mediosConDif === 0)
                <p class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300" role="status">
                    <x-filament::icon icon="heroicon-o-check-circle" class="size-5 shrink-0" />
                    Los importes declarados coinciden con lo esperado.
                </p>
            @endif

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
                @if ($this->turnoSinVentas())
                    <x-filament::button
                        type="submit"
                        color="gray"
                        size="lg"
                        icon="heroicon-m-x-circle"
                        class="w-full min-w-40 sm:w-auto"
                    >
                        Anular turno vacío
                    </x-filament::button>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No tuvo ventas. Se anula y no entra en el día.
                    </p>
                @elseif (! $this->confirmandoCierre)
                    <x-filament::button
                        type="button"
                        color="danger"
                        size="lg"
                        icon="heroicon-m-lock-closed"
                        wire:click="revisarCierre"
                        :disabled="$cierreBloqueado"
                        class="w-full min-w-40 sm:w-auto"
                    >
                        Revisar cierre
                    </x-filament::button>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($cierreBloqueado)
                            Completá la justificación para habilitar el cierre.
                        @else
                            El cierre pide una confirmación. No se deshace.
                        @endif
                    </p>
                @else
                    <x-filament::button
                        type="submit"
                        color="danger"
                        size="lg"
                        icon="heroicon-m-lock-closed"
                        class="w-full min-w-40 sm:w-auto"
                    >
                        Confirmar cierre
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="cancelarConfirmacion"
                    >
                        Volver
                    </x-filament::button>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($justificacionCompleta)
                            El arqueo se registrará con la diferencia y su justificación.
                        @else
                            Si los montos calzan, confirmá para cerrar el turno.
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </section>
</div>
