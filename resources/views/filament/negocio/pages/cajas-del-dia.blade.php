<x-filament-panels::page>
    @php
        $moneda = $this->moneda();
        $consolidado = $this->consolidado();
        $fechaLabel = is_array($consolidado)
            ? \Illuminate\Support\Carbon::parse($consolidado['fecha'])->format('d/m/Y')
            : null;
        $faltante = is_array($consolidado) ? $consolidado['faltante_cerrado'] : null;
        $porDeclarar = is_array($consolidado)
            ? $consolidado['sesiones_abiertas'] + $consolidado['sesiones_pendientes']
            : 0;
        $hayFaltante = is_string($faltante) && str_starts_with($faltante, '-');
        $haySobra = is_string($faltante) && $faltante !== '0.00' && ! $hayFaltante;
        $vendedores = $this->vendedores();
        $sesionesVendedor = $this->sesionesDelVendedor();
        $nombreVendedor = $sesionesVendedor[0]['cajero'] ?? 'Vendedor';
        $cierreDia = $this->cierreDia();
        $puedeCerrarDia = is_array($consolidado)
            && $cierreDia === null
            && $consolidado['sesiones_abiertas'] === 0
            && $consolidado['sesiones_pendientes'] === 0
            && $consolidado['sesiones_cerradas'] > 0;
    @endphp

    <div class="w-full space-y-5">
        <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <header class="border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Filtro</h2>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Elegí sucursal y día. Cada fila es un vendedor: sus sesiones y ventas se abren al hacer clic.
                </p>
            </header>
            <div class="p-5">
                {{ $this->form }}
            </div>
        </section>

        @if ($consolidado === null)
            <section class="rounded-xl bg-white px-5 py-10 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">Elegí una sucursal y un día para ver el consolidado.</p>
            </section>
        @elseif ($consolidado['sesiones'] === [])
            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex flex-col items-center gap-3 px-5 py-12 text-center">
                    <div class="flex size-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-200">
                        <x-filament::icon icon="heroicon-o-calendar-days" class="size-7" />
                    </div>
                    <div class="max-w-md space-y-1.5">
                        <p class="text-base font-semibold text-gray-950 dark:text-white">
                            No hay movimientos el {{ $fechaLabel }}
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            En <span class="font-medium">{{ $consolidado['sucursal'] }}</span> nadie abrió caja ese día.
                        </p>
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            Verificá la fecha: las sesiones se agrupan por el día en que se abrió el turno.
                        </p>
                    </div>
                </div>
            </section>
        @else
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                        <x-filament::icon icon="heroicon-o-banknotes" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Cobrado en el día</p>
                        <p class="text-base font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $consolidado['total_cobros'] }}</p>
                    </div>
                </div>
                <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                        <x-filament::icon icon="heroicon-o-wallet" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Fondos de apertura</p>
                        <p class="text-base font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ $consolidado['total_apertura'] }}</p>
                    </div>
                </div>
                <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                        <x-filament::icon icon="heroicon-o-check-circle" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Sesiones cerradas</p>
                        <p class="text-base font-bold tabular-nums text-gray-950 dark:text-white">{{ $consolidado['sesiones_cerradas'] }} de {{ count($consolidado['sesiones']) }}</p>
                    </div>
                </div>
                <div class="flex min-w-0 items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                        <x-filament::icon icon="heroicon-o-scale" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Diferencia neta declarada</p>
                        <p class="text-base font-bold tabular-nums text-gray-950 dark:text-white">{{ $consolidado['sesiones_cerradas'] > 0 && collect($consolidado['por_metodo'])->contains(fn (array $fila): bool => $fila['diferencia'] !== null) ? $moneda.' '.$faltante : '—' }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Solo sesiones cerradas</p>
                    </div>
                </div>
            </div>
            @if ($porDeclarar > 0 || $hayFaltante || $haySobra)
                <div @class([
                    'space-y-1 rounded-xl px-4 py-3 text-sm',
                    'bg-danger-50 text-danger-800 dark:bg-danger-500/10 dark:text-danger-200' => $hayFaltante,
                    'bg-amber-50 text-amber-950 dark:bg-amber-400/10 dark:text-amber-100' => ! $hayFaltante,
                ])>
                    @if ($porDeclarar > 0)
                        <p>Hay {{ $porDeclarar }} {{ $porDeclarar === 1 ? 'sesión de caja pendiente' : 'sesiones de caja pendientes' }} de declarar: {{ $moneda }} {{ $consolidado['pendiente_contar'] }}. Cerrá o contá esas sesiones para habilitar el cierre general.</p>
                    @endif
                    @if ($hayFaltante)
                        <p>Falta {{ $moneda }} {{ ltrim($faltante, '-') }} en las sesiones ya cerradas.</p>
                    @elseif ($haySobra)
                        <p>Sobra {{ $moneda }} {{ $faltante }} en las sesiones ya cerradas.</p>
                    @endif
                </div>
            @endif

            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <header class="border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Consolidado por método de pago</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Esperado y declarado corresponden a sesiones cerradas. Pendiente incluye sesiones abiertas y por contar.
                    </p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-start">
                        <thead>
                            <tr class="border-b border-gray-200 bg-primary-50/70 dark:border-white/10 dark:bg-primary-400/10">
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Medio</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Esperado</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Declarado</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Cuadre</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Pendiente</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($consolidado['por_metodo'] as $fila)
                                @php($dif = $fila['diferencia'])
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="px-5 py-2.5 font-medium text-gray-950 dark:text-white">{{ $fila['etiqueta'] }}</td>
                                    <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $fila['esperado'] }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">
                                        {{ $fila['declarado'] === null ? '—' : $moneda.' '.$fila['declarado'] }}
                                    </td>
                                    <td @class([
                                        'px-5 py-2.5 font-medium',
                                        'text-gray-400 dark:text-gray-500' => $this->tonoCuadre($dif) === 'muted',
                                        'text-danger-600 dark:text-danger-400' => $this->tonoCuadre($dif) === 'danger',
                                        'text-amber-700 dark:text-amber-300' => $this->tonoCuadre($dif) === 'warning',
                                    ])>
                                        {{ $dif === null ? '—' : $this->textoCuadre($dif) }}
                                    </td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">
                                        {{ $fila['pendiente'] === '0.00' ? '—' : $moneda.' '.$fila['pendiente'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 bg-primary-50/50 dark:border-white/10 dark:bg-primary-400/10">
                                <td class="px-5 py-2.5 font-semibold text-gray-950 dark:text-white">Totales cobrados</td>
                                <td class="px-5 py-2.5 tabular-nums font-semibold text-gray-950 dark:text-white" colspan="4">
                                    {{ $moneda }} {{ $consolidado['total_cobros'] }}
                                    @if ((float) $consolidado['total_reembolsos'] > 0)
                                        <span class="ms-2 font-normal text-gray-500 dark:text-gray-400">
                                            · Reembolsos {{ $moneda }} {{ $consolidado['total_reembolsos'] }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <header class="border-b border-gray-200 px-5 py-3.5 dark:border-white/10">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Vendedores del día</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Un renglón por persona. Clic para ver sus sesiones.
                    </p>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-start">
                        <thead>
                            <tr class="border-b border-gray-200 bg-primary-50/70 dark:border-white/10 dark:bg-primary-400/10">
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Vendedor</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Sesiones</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Apertura</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Cobros</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Estado</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400">Cuadre</th>
                                <th class="px-5 py-2.5 font-medium text-gray-500 dark:text-gray-400"><span class="sr-only">Abrir</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vendedores as $fila)
                                <tr
                                    class="cursor-pointer border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5"
                                    wire:click="verVendedor({{ $fila['user_id'] }})"
                                    wire:key="vendedor-{{ $fila['user_id'] }}"
                                >
                                    <td class="px-5 py-2.5 font-medium text-gray-950 dark:text-white">{{ $fila['cajero'] }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $fila['sesiones'] }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $fila['monto_apertura'] }}</td>
                                    <td class="px-5 py-2.5 tabular-nums text-gray-700 dark:text-gray-200">{{ $moneda }} {{ $fila['total_cobros'] }}</td>
                                    <td class="px-5 py-2.5">
                                        @if ($fila['abiertas'] > 0)
                                            <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">Abierta</span>
                                        @elseif ($fila['pendientes'] > 0)
                                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">Por contar</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-700 dark:bg-white/10 dark:text-gray-200">Cerrada</span>
                                        @endif
                                    </td>
                                    <td @class([
                                        'px-5 py-2.5 font-medium',
                                        'text-gray-400 dark:text-gray-500' => $this->tonoCuadre($fila['diferencia_efectivo'], $fila['sin_declaracion']) === 'muted',
                                        'text-danger-600 dark:text-danger-400' => $this->tonoCuadre($fila['diferencia_efectivo'], $fila['sin_declaracion']) === 'danger',
                                        'text-amber-700 dark:text-amber-300' => $this->tonoCuadre($fila['diferencia_efectivo'], $fila['sin_declaracion']) === 'warning',
                                    ])>
                                        {{ $this->textoCuadre($fila['diferencia_efectivo'], $fila['sin_declaracion']) }}
                                    </td>
                                    <td class="px-5 py-2.5 text-end">
                                        <x-filament::icon icon="heroicon-o-chevron-right" class="ms-auto size-4 text-gray-400" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white px-5 py-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-lock-closed" class="mt-0.5 size-5 shrink-0 text-gray-500 dark:text-gray-400" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Cierre general de la sucursal</h3>
                        @if ($cierreDia)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Día cerrado por {{ $cierreDia->usuario?->name ?? 'el encargado' }} el {{ $cierreDia->fecha_cierre?->format('d/m/Y H:i') }}. No se abren más sesiones de esta fecha.</p>
                        @elseif ($porDeclarar > 0)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $consolidado['sesiones_cerradas'] }} cerrada{{ $consolidado['sesiones_cerradas'] === 1 ? '' : 's' }} · {{ $porDeclarar }} pendiente{{ $porDeclarar === 1 ? '' : 's' }} de declarar. Cerrá o contá las sesiones pendientes para continuar.</p>
                        @elseif ($puedeCerrarDia)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Las sesiones están contadas. Al cerrar el día no se podrán abrir más sesiones de esta fecha.</p>
                        @else
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Se necesita al menos una sesión cerrada para cerrar el día.</p>
                        @endif
                    </div>
                </div>
                @if (! $cierreDia)
                    <form wire:submit="cerrarDia">
                        <x-filament::button type="submit" icon="heroicon-m-lock-closed" :disabled="! $puedeCerrarDia">
                            Cerrar el día
                        </x-filament::button>
                    </form>
                @endif
            </section>
            <x-filament::modal id="turnos-vendedor" class="caja-historial-modal" sticky-header width="7xl" heading="Sesiones de {{ $nombreVendedor }}" description="Consultá las sesiones de este vendedor y sus ventas.">
                @if ($this->vendedorId !== null && $consolidado !== null && $sesionesVendedor !== [])
                    <livewire:negocio.caja.sesiones-del-dia
                        :fecha="$consolidado['fecha']"
                        :vendedor-id="$this->vendedorId"
                        :sucursal-id="$consolidado['sucursal_id']"
                        :key="'supervision-'.$consolidado['sucursal_id'].'-'.$consolidado['fecha'].'-'.$this->vendedorId"
                    />
                @endif
            </x-filament::modal>
        @endif
    </div>
</x-filament-panels::page>
