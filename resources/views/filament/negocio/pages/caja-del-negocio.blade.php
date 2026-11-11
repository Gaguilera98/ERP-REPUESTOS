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
            <section class="space-y-3">
                @foreach ($pendientes as $pendiente)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-400/30 dark:bg-amber-400/10">
                        <div>
                            <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">Turno anterior pendiente de contar</p>
                            <p class="text-xs text-amber-800 dark:text-amber-200">
                                Sesión #{{ $pendiente->id }} · {{ $pendiente->sucursal?->nombre }} · Apertura {{ $pendiente->fecha_apertura->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        @if (auth('tenant')->user()?->can('update', $pendiente))
                            <x-filament::button
                                type="button"
                                color="warning"
                                icon="heroicon-o-clock"
                                wire:click="contar({{ $pendiente->id }})"
                            >
                                Revisar turno anterior
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
            </section>
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
