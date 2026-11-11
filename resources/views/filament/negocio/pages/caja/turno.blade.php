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
    <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
        <p class="font-semibold text-gray-950 dark:text-white">Turno actual · Sesión #{{ $sesion->id }} · {{ auth('tenant')->user()?->name }}</p>
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
    @include('filament.negocio.pages.caja.resumen-turno')

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
