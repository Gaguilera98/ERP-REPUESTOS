@use(App\CajaChica\Permisos)
@use(App\Enums\EstadoCajaChicaMovimiento)
@use(App\Enums\SentidoCajaChica)
@use(App\Models\NegocioConfiguracion)

<x-filament-panels::page>
    @php
        $moneda = NegocioConfiguracion::codigoMoneda();
        $usuario = auth('tenant')->user();
    @endphp

    <div class="space-y-5">
        @forelse ($this->fondos() as $fondo)
            @php
                $vales = $fondo->valesPendientes;
                $puedeRendir = (int) $fondo->responsable_id === (int) $usuario->id && $usuario->can(Permisos::OPERAR);
            @endphp

            <x-filament::section>
                <x-slot name="heading">{{ $fondo->sucursal?->nombre }}</x-slot>
                <x-slot name="description">Responsable: {{ $fondo->responsable?->name }}</x-slot>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Saldo del fondo</p>
                        <p class="text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $fondo->saldo_actual }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Vales por rendir</p>
                        <p class="text-lg font-semibold tabular-nums">{{ $vales->count() }}</p>
                    </div>
                </div>

                @if ($vales->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-400/30 dark:bg-amber-400/10">
                        <h3 class="px-1 text-sm font-semibold text-amber-900 dark:text-amber-200">Pendientes de rendir</h3>
                        <ul class="mt-1 max-h-64 divide-y divide-amber-200 overflow-y-auto dark:divide-amber-400/20">
                            @foreach ($vales as $vale)
                                <li class="flex flex-wrap items-center justify-between gap-2 px-1 py-2" wire:key="vale-caja-chica-{{ $vale->id }}">
                                    <div>
                                        <p class="text-sm font-medium">{{ $vale->concepto }}</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $vale->persona_recibe }} · {{ $vale->created_at->format('d/m/Y H:i') }}</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm font-semibold tabular-nums">{{ $moneda }} {{ $vale->monto }}</span>
                                        @if ($puedeRendir)
                                            <x-filament::button type="button" color="gray" size="sm" wire:click="mountAction('rendirVale', { valeId: {{ $vale->id }} })">
                                                Rendir
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold">Movimientos recientes</h3>
                        <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-clock" wire:click="verHistorial({{ $fondo->id }})">
                            Ver historial
                        </x-filament::button>
                    </div>
                    <ul class="mt-2 divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($fondo->movimientos as $movimiento)
                            <li class="flex flex-wrap items-start justify-between gap-2 py-2.5" wire:key="movimiento-caja-chica-{{ $movimiento->id }}">
                                <div>
                                    <p class="text-sm font-medium">{{ $movimiento->concepto }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $movimiento->tipo->etiqueta() }} · {{ $movimiento->created_at->format('d/m/Y H:i') }}
                                    </p>
                                    @if ($movimiento->monto_gasto !== null)
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Gastado {{ $moneda }} {{ $movimiento->monto_gasto }} · Devuelto {{ $moneda }} {{ $movimiento->monto_sobrante }}
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="match ($movimiento->estado) {
                                        EstadoCajaChicaMovimiento::PendienteRendir, EstadoCajaChicaMovimiento::PendienteComprobante, EstadoCajaChicaMovimiento::EnRevision => 'warning',
                                        EstadoCajaChicaMovimiento::RendidoCompleto => 'success',
                                        default => 'gray',
                                    }">
                                        {{ $movimiento->estado->etiqueta() }}
                                    </x-filament::badge>
                                    <span @class([
                                        'text-sm font-semibold tabular-nums',
                                        'text-success-700 dark:text-success-400' => $movimiento->afecta_saldo && $movimiento->sentido === SentidoCajaChica::Entrada,
                                    ])>
                                        {{ $movimiento->importeVisible($moneda) }}
                                    </span>
                                    @if ($movimiento->valeRelacionadoId() !== null)
                                        <x-filament::button type="button" size="sm" color="gray" wire:click="mountAction('verVale', { valeId: {{ $movimiento->valeRelacionadoId() }} })">
                                            Ver vale
                                        </x-filament::button>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="py-2 text-sm text-gray-500 dark:text-gray-400">Todavía no hay movimientos.</li>
                        @endforelse
                    </ul>
                </div>

                <details class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10">
                    <summary class="cursor-pointer text-sm font-medium">Arqueos ({{ $fondo->arqueos->count() }})</summary>
                    <ul class="mt-2 max-h-64 divide-y divide-gray-200 overflow-y-auto text-sm dark:divide-white/10">
                        @forelse ($fondo->arqueos as $arqueo)
                            <li class="py-2">
                                {{ $arqueo->tipo->etiqueta() }} · {{ $arqueo->created_at->format('d/m/Y H:i') }}
                                <p class="text-xs text-gray-500 dark:text-gray-400">Esperado {{ $moneda }} {{ $arqueo->teorico }} · Contado {{ $moneda }} {{ $arqueo->contado }}</p>
                                @if ($arqueo->justificacion)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $arqueo->justificacion }}</p>
                                @endif
                            </li>
                        @empty
                            <li class="py-2 text-gray-500 dark:text-gray-400">Todavía no hay arqueos.</li>
                        @endforelse
                    </ul>
                </details>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">No tenés fondos de caja chica disponibles.</p>
                @if ($usuario->can(Permisos::FONDOS))
                    <x-filament::button type="button" class="mt-3" icon="heroicon-o-plus" wire:click="mountAction('abrirFondo')">
                        Abrir fondo
                    </x-filament::button>
                @endif
            </x-filament::section>
        @endforelse
    </div>

    @push('caja-chica-action-modals')
        <x-filament-actions::modals />
    @endpush

    <x-filament::modal id="historial-caja-chica" heading="Historial de movimientos" width="5xl" sticky-header>
        @if ($this->fondoHistorialId !== null)
            {{ $this->table }}
        @endif
    </x-filament::modal>

    @stack('caja-chica-action-modals')
</x-filament-panels::page>
