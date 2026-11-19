<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
            @foreach ($errors->all() as $mensaje)
                <p>{{ $mensaje }}</p>
            @endforeach
        </div>
    @endif

    @if (! $this->importacion)
        <form wire:submit="revisar" class="space-y-6">
            {{ $this->form }}
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="revisar,data.archivo">Revisar archivo</x-filament::button>
                <span wire:loading wire:target="revisar" role="status" class="text-sm text-gray-500 dark:text-gray-400">Leyendo y validando las filas…</span>
            </div>
        </form>
        <x-filament::section heading="Mis importaciones">
            {{ $this->table }}
        </x-filament::section>
    @else
        @php
            $carga = $this->importacion;
            $revision = $carga->revision;
            $moneda = $carga->moneda;
            $paginas = max(1, (int) ceil(count($carga->filas) / 20));
            $pagina = max(1, min($paginas, $this->paginaRevision));
            $filas = array_slice($carga->filas, ($pagina - 1) * 20, 20);
            $resueltas = collect($revision['filas'])->keyBy('fila');
        @endphp
        <x-filament::section>
            <x-slot name="heading">{{ $carga->codigo() }} · {{ $carga->archivo }}</x-slot>
            <x-slot name="description">{{ $carga->almacen?->nombre ?? 'Solo catálogo' }} @if ($carga->fecha_inventario) · Inventario al {{ $carga->fecha_inventario->format('d/m/Y') }} @endif · {{ $carga->usuario->name }}</x-slot>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                    <p><span class="block text-gray-500 dark:text-gray-400">Productos nuevos</span><strong class="text-xl">{{ $revision['nuevos'] }}</strong></p>
                    <p><span class="block text-gray-500 dark:text-gray-400">Se actualizarán</span><strong class="text-xl">{{ $revision['actualizados'] }}</strong></p>
                    <p><span class="block text-gray-500 dark:text-gray-400">Conservan sus datos</span><strong class="text-xl">{{ $revision['conservados'] }}</strong></p>
                    @if ($carga->almacen_id)
                        <p><span class="block text-gray-500 dark:text-gray-400">Lotes iniciales</span><strong class="text-xl">{{ $revision['lotes'] }}</strong></p>
                        <p><span class="block text-gray-500 dark:text-gray-400">Valor del inventario inicial</span><strong class="text-xl text-primary-600 dark:text-primary-400">{{ $moneda }} {{ \App\Compras\Montos::dos($revision['valor']) }}</strong></p>
                    @endif
                </div>
                <x-filament::badge :color="match ($carga->estado) { 'completada' => 'success', 'en_cola' => 'info', 'fallida' => 'danger', default => 'warning' }">
                    {{ match ($carga->estado) { 'completada' => 'Completada', 'en_cola' => 'En proceso', 'fallida' => 'Requiere revisión', default => 'Pendiente de confirmar' } }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        @if ($carga->estado === 'en_cola')
            <div wire:poll.3s class="flex items-center gap-3 rounded-xl bg-primary-500/10 p-4 text-sm" role="status">
                <x-filament::loading-indicator class="h-5 w-5" />
                <span>Procesando la importación. Podés seguir trabajando y regresar desde Mis importaciones.</span>
            </div>
        @elseif ($carga->estado === 'completada')
            <p role="status" class="rounded-xl bg-success-500/10 p-4 text-sm text-success-700 dark:text-success-400">
                Importación completada el {{ $carga->completada_at->format('d/m/Y H:i') }}.
                @if ($revision['lotes'] > 0) Los lotes quedaron registrados como entrada por inventario inicial. @endif
            </p>
        @endif
        @if (filled($carga->resultado['aviso'] ?? null))
            <p role="alert" class="rounded-xl bg-warning-500/10 p-4 text-sm text-warning-700 dark:text-warning-400">{{ $carga->resultado['aviso'] }}</p>
        @endif
        @if ($revision['errores'] !== [])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-danger-500/10 p-4">
                <p class="text-sm text-danger-700 dark:text-danger-300">{{ count($revision['errores']) }} filas necesitan corrección. Revisá sus mensajes y volvé a subir el archivo.</p>
                <x-filament::button color="gray" wire:click="descargarErrores" icon="heroicon-o-arrow-down-tray">Descargar filas con errores</x-filament::button>
            </div>
        @endif

        <x-filament::section heading="Revisión de las filas">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th scope="col" class="p-3">Fila</th><th scope="col" class="p-3">Producto</th><th scope="col" class="p-3">Acción</th>
                            <th scope="col" class="p-3 text-right">Precio menor</th><th scope="col" class="p-3 text-right">Precio mayor</th>
                            @if ($carga->almacen_id)
                                <th scope="col" class="p-3 text-right">Cantidad inicial</th><th scope="col" class="p-3 text-right">Costo unitario</th><th scope="col" class="p-3 text-right">Valor del lote</th><th scope="col" class="p-3">Proveedor</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($filas as $fila)
                            @php
                                $resuelta = $resueltas->get($fila['fila']);
                                $erroresFila = $revision['errores'][$fila['fila']] ?? [];
                            @endphp
                            <tr wire:key="importacion-fila-{{ $carga->id }}-{{ $fila['fila'] }}" @class(['bg-danger-500/5' => $erroresFila !== []])>
                                <td class="p-3 text-gray-500 dark:text-gray-400">{{ $fila['fila'] }}</td>
                                <th scope="row" class="min-w-48 p-3 font-medium">{{ $resuelta['nombre'] ?? $fila['nombre'] }}<span class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $fila['sku'] }}</span></th>
                                <td class="p-3">
                                    <x-filament::badge :color="$erroresFila !== [] ? 'danger' : (($resuelta['accion'] ?? '') === 'crear' ? 'success' : 'gray')">
                                        {{ $erroresFila !== [] ? 'Corregir' : match ($resuelta['accion'] ?? '') { 'crear' => 'Crear', 'actualizar' => 'Actualizar', default => 'Conservar' } }}
                                    </x-filament::badge>
                                </td>
                                <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ $resuelta['precio_menor'] ?? $fila['precio_menor'] }}</td>
                                <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ $resuelta['precio_mayor'] ?? ($fila['precio_mayor'] ?: '—') }}</td>
                                @if ($carga->almacen_id)
                                    <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ $fila['cantidad_inicial'] ?: '—' }} {{ $resuelta['unidad'] ?? '' }}</td>
                                    <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ filled($fila['costo_unitario']) ? $fila['costo_unitario'] : '—' }}</td>
                                    <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ isset($resuelta['cantidad']) ? $moneda.' '.\App\Compras\Montos::multiplicar($resuelta['cantidad'], $resuelta['costo'], 2) : '—' }}</td>
                                    <td class="p-3">{{ $fila['proveedor'] ?: 'Sin especificar' }}</td>
                                @endif
                            </tr>
                            @if ($erroresFila !== [])
                                <tr><td colspan="{{ $carga->almacen_id ? 9 : 5 }}" class="px-3 pb-3 text-sm text-danger-600 dark:text-danger-400">{{ implode(' ', $erroresFila) }}</td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <span class="text-sm text-gray-500 dark:text-gray-400">Página {{ $pagina }} de {{ $paginas }} · {{ count($carga->filas) }} filas</span>
                <div class="flex gap-2">
                    <x-filament::button color="gray" size="sm" wire:click="$set('paginaRevision', {{ $pagina - 1 }})" :disabled="$pagina <= 1">Anterior</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="$set('paginaRevision', {{ $pagina + 1 }})" :disabled="$pagina >= $paginas">Siguiente</x-filament::button>
                </div>
            </div>
        </x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::button color="gray" wire:click="nuevaCarga">Volver a mis importaciones</x-filament::button>
            @if (in_array($carga->estado, ['revision', 'fallida'], true) && $revision['errores'] === [])
                {{ $this->confirmarAction }}
            @endif
        </div>
    @endif
</x-filament-panels::page>
