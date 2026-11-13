<x-filament-panels::page>
    @php
        $moneda = $this->moneda();
        $arqueo = $this->arqueoTurno();
        $sesion = $this->sesionActual();
        $abierta = $sesion !== null;
        $pendientes = $this->pendientes();

        $declarados = is_array($this->data['medios_declarados'] ?? null)
            ? $this->data['medios_declarados']
            : [];
        $diferencias = $abierta ? $this->diferenciasPorMedio($declarados) : [];
        $mediosConDif = collect($diferencias)->filter(fn (string $d): bool => $d !== '0.00')->count();
        $justificacion = trim((string) ($this->data['justificacion_diferencia'] ?? ''));
        $cierreBloqueado = $abierta && $mediosConDif > 0 && $justificacion === '';
    @endphp

    <div class="w-full space-y-5">
        @if ($pendientes->isNotEmpty())
            <details wire:key="caja-turnos-pendientes" class="group rounded-xl border border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-2 rounded-xl px-4 py-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 [&::-webkit-details-marker]:hidden">
                    <x-filament::icon icon="heroicon-o-clock" class="size-5 shrink-0" />
                    <span class="min-w-0 flex-1 text-sm font-semibold">
                        {{ $pendientes->count() === 1 ? '1 turno anterior pendiente de contar' : $pendientes->count().' turnos anteriores pendientes de contar' }}
                    </span>
                    <span class="flex items-center gap-1.5 text-sm font-medium">
                        <span class="group-open:hidden">{{ $pendientes->count() === 1 ? 'Ver turno' : 'Ver turnos' }}</span>
                        <span class="hidden group-open:inline">Ocultar</span>
                        <x-filament::icon icon="heroicon-o-chevron-down" class="size-4 shrink-0 group-open:rotate-180" />
                    </span>
                </summary>

                <ul class="max-h-64 divide-y divide-amber-200 overflow-y-auto border-t border-amber-200 px-4 dark:divide-amber-400/20 dark:border-amber-400/20">
                    @foreach ($pendientes as $pendiente)
                        <li wire:key="caja-turno-pendiente-{{ $pendiente->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">Sesión #{{ $pendiente->id }} · {{ $pendiente->sucursal?->nombre }}</p>
                                <p class="text-xs text-amber-800 dark:text-amber-200">Apertura {{ $pendiente->fecha_apertura->format('d/m/Y H:i') }}</p>
                            </div>
                            @if (auth('tenant')->user()?->can('update', $pendiente))
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="sm"
                                    aria-label="Revisar turno anterior #{{ $pendiente->id }}"
                                    wire:click="contar({{ $pendiente->id }})"
                                >
                                    Revisar
                                </x-filament::button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        <form wire:submit="{{ $abierta ? 'cerrar' : 'abrir' }}" class="space-y-5">
            @if ($abierta && $arqueo !== null)
                @include('filament.negocio.pages.caja.turno', [
                    'sesion' => $sesion,
                    'arqueo' => $arqueo,
                    'moneda' => $moneda,
                    'diferencias' => $diferencias,
                    'mediosConDif' => $mediosConDif,
                    'cierreBloqueado' => $cierreBloqueado,
                    'justificacionCompleta' => $mediosConDif > 0 && $justificacion !== '',
                ])
            @else
                @include('filament.negocio.pages.caja.abrir')
            @endif
        </form>

    </div>

    {{-- Reserve the action modals before the history table renders its own container. --}}
    @push('caja-del-negocio-action-modals')
        <x-filament-actions::modals />
    @endpush

    <x-filament::modal
        id="historial-sesiones-caja"
        class="caja-historial-modal"
        sticky-header
        width="7xl"
        heading="Historial de mi caja por día"
        description="Elegí un día para ver sus sesiones y después las ventas de cada turno."
    >
        {{ $this->table }}
    </x-filament::modal>

    @stack('caja-del-negocio-action-modals')
</x-filament-panels::page>
