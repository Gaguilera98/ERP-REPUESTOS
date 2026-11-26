<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-300">
            @foreach ($errors->all() as $mensaje)
                <p>{{ $mensaje }}</p>
            @endforeach
        </div>
    @endif

    @if ($this->archivoPendiente)
        <form wire:submit="relacionarColumnas" class="space-y-6">
            {{ $this->columnasForm }}
            @if ($this->faltanExistenciasEnArchivo())
                <div role="alert" class="space-y-3 rounded-xl bg-warning-500/10 p-4 text-sm text-warning-700 dark:text-warning-400">
                    <p>Activaste existencias iniciales, pero no encontramos cantidades y costos completos en el archivo. Podés completarlos en la revisión o continuar solo con productos.</p>
                    <x-filament::button color="gray" type="button" wire:click="relacionarSoloProductos" wire:loading.attr="disabled">Continuar solo con productos</x-filament::button>
                </div>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="relacionarColumnas">Continuar a revisión</x-filament::button>
                <x-filament::button color="gray" wire:click="nuevaCarga">Cambiar archivo</x-filament::button>
            </div>
        </form>
    @elseif (! $this->importacion)
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
        @endphp
        <x-filament::section>
            <x-slot name="heading">{{ $carga->codigo() }} · {{ $carga->archivo }}</x-slot>
            <x-slot name="description">{{ $carga->almacen?->nombre ?? 'Solo catálogo' }} @if ($carga->fecha_inventario) · Inventario al {{ $carga->fecha_inventario->format('d/m/Y') }} @endif · {{ $carga->usuario->name }}</x-slot>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                    <p><span class="block text-gray-500 dark:text-gray-400">Variantes nuevas</span><strong class="text-xl">{{ $revision['nuevos'] }}</strong></p>
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
        @if ($carga->almacen_id !== null && $revision['lotes'] === 0 && in_array($carga->estado, ['revision', 'fallida'], true))
            <div role="alert" class="space-y-3 rounded-xl bg-warning-500/10 p-4 text-sm text-warning-700 dark:text-warning-400">
                <p>{{ $revision['errores'] === [] || ! \App\Catalogo\ImportarProductos::contieneDatosDeExistencias($carga->filas) ? \App\Catalogo\ImportarProductos::EXISTENCIAS_REQUERIDAS : 'Corregí las filas pendientes para validar las existencias iniciales. No se ingresará stock hasta confirmar una revisión válida.' }}</p>
                <p>Para completar el stock, abrí Revisar producto en cada fila e ingresá su cantidad inicial y costo unitario.</p>
                {{ $this->soloProductosAction }}
            </div>
        @endif
        @if ($revision['actualizados'] > 0 && in_array($carga->estado, ['revision', 'fallida'], true))
            <p class="rounded-xl bg-warning-500/10 p-4 text-sm text-warning-700 dark:text-warning-400">Se actualizarán productos existentes por coincidencia de SKU. Abrí Revisar producto para comparar los valores actuales y propuestos antes de confirmar.</p>
        @endif
        @if ($revision['errores'] !== [])
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-danger-500/10 p-4">
                <p class="text-sm text-danger-700 dark:text-danger-300">{{ count($revision['errores']) }} filas necesitan corrección. Corregilas en la tabla o seleccioná varias para asignar un valor en grupo.</p>
                <x-filament::button color="gray" wire:click="descargarErrores" wire:loading.attr="disabled" wire:target="descargarErrores" icon="heroicon-o-arrow-down-tray">Descargar pendientes para completar</x-filament::button>
            </div>
        @endif

        <x-filament::section heading="Revisión de las filas">
            <livewire:negocio.revision-importacion-productos :importacion-id="$carga->id" :key="'revision-importacion-'.$carga->id.'-'.$carga->estado.'-'.($carga->almacen_id ?? 'catalogo')" />
        </x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::button color="gray" wire:click="nuevaCarga">Volver a mis importaciones</x-filament::button>
            @if (in_array($carga->estado, ['revision', 'fallida'], true) && $revision['errores'] === [] && ($carga->almacen_id === null || $revision['lotes'] > 0))
                {{ $this->confirmarAction }}
            @endif
        </div>
    @endif
</x-filament-panels::page>
