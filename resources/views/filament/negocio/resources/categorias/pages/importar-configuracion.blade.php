<x-filament-panels::page>
    @if ($errors->any())
        <div role="alert" class="space-y-1 rounded-xl bg-danger-500/10 p-4 text-sm text-danger-700 dark:text-danger-300">
            @foreach ($errors->all() as $mensaje)
                <p>{{ $mensaje }}</p>
            @endforeach
        </div>
    @endif
    @if ($revision === null)
        <form wire:submit="revisar" class="space-y-6">
            {{ $this->form }}
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="revisar,data.archivo">Revisar configuración</x-filament::button>
        </form>
    @else
        <x-filament::section heading="Revisar antes de crear" :description="$nombreArchivo">
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach (['unidades' => 'Unidades nuevas', 'categorias' => 'Categorías nuevas', 'atributos' => 'Atributos nuevos'] as $grupo => $titulo)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $titulo }}<strong class="block text-2xl text-gray-950 dark:text-white">{{ collect($revision[$grupo])->where('accion', 'crear')->count() }}</strong></p>
                @endforeach
            </div>
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Los registros marcados Reutilizar se conservarán. No se cargan productos ni existencias.</p>
        </x-filament::section>
        @if ($revision['errores'] !== [])
            <div role="alert" class="space-y-2 rounded-xl bg-warning-500/10 p-4 text-sm text-warning-800 dark:text-warning-200">
                <p class="font-semibold">Resolvé los conflictos antes de confirmar</p>
                @foreach ($revision['errores'] as $mensaje)
                    <p>{{ $mensaje }}</p>
                @endforeach
                <p>Podés relacionar unidades existentes abajo. Para otros cambios, corregí el Excel y elegí Cambiar archivo.</p>
            </div>
        @endif
        @if ($revision['unidades'] !== [])
            <x-filament::section heading="Unidades de la carga" collapsible>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($revision['unidades'] as $fila)
                        <div class="min-w-0 space-y-2 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <strong>{{ $fila['datos']['nombre'] }} ({{ $fila['datos']['simbolo'] }})</strong>
                                <x-filament::badge :color="match ($fila['accion']) { 'crear' => 'success', 'conflicto' => 'warning', default => 'gray' }">{{ ucfirst($fila['accion']) }}</x-filament::badge>
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $fila['codigo'] }} · Fila {{ $fila['_fila'] }} · {{ $fila['datos']['uso'] }} · {{ $fila['datos']['magnitud'] }}</p>
                            @if ($fila['datos']['uso'] === 'cantidad')
                                <p class="text-sm">Factor {{ $fila['datos']['factor_a_referencia'] }} · {{ $fila['datos']['decimales'] }} decimales · {{ $fila['datos']['es_referencia'] ? 'Es referencia' : 'No es referencia' }}</p>
                            @endif
                            @if ($fila['accion'] === 'reutilizar')
                                <p class="text-sm">Se usará: {{ $fila['destino'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
            <form wire:submit="actualizarRevision" class="space-y-4">
                {{ $this->asignacionesForm }}
                <x-filament::button type="submit" color="gray" icon="heroicon-o-arrow-path" wire:loading.attr="disabled">Actualizar revisión</x-filament::button>
            </form>
        @endif
        <x-filament::section heading="Categorías y su ficha técnica">
            <div class="space-y-3">
                @foreach ($revision['categorias'] as $codigo => $categoria)
                    @php($atributos = collect($revision['atributos'])->where('categoria', $codigo))
                    <details class="rounded-xl border border-gray-200 p-4 dark:border-white/10" @if (count($revision['categorias']) === 1) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold">{{ $categoria['nombre'] }} · {{ ucfirst($categoria['accion']) }} · {{ $atributos->count() }} atributos</summary>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $codigo }} · Fila {{ $categoria['_fila'] }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @forelse ($atributos as $atributo)
                                <div class="min-w-0 space-y-1 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <strong>{{ $atributo['datos']['etiqueta'] }}</strong>
                                        <x-filament::badge :color="$atributo['accion'] === 'conflicto' ? 'warning' : 'gray'">{{ ucfirst($atributo['accion']) }}</x-filament::badge>
                                    </div>
                                    <p class="break-words text-gray-500 dark:text-gray-400">{{ $atributo['datos']['clave'] }} · {{ $atributo['datos']['tipo'] }} · {{ $atributo['datos']['obligatorio'] ? 'Obligatorio' : 'Opcional' }}</p>
                                    @if ($atributo['unidad'] !== '')
                                        <p>Unidad: {{ $revision['unidades'][$atributo['unidad']]['destino'] }}</p>
                                    @endif
                                    @if ($atributo['datos']['opciones'])
                                        <p class="break-words">Opciones: {{ implode(' · ', $atributo['datos']['opciones']) }}</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-500 dark:text-gray-400">Sin atributos en este archivo. Los existentes se conservan.</p>
                            @endforelse
                        </div>
                    </details>
                @endforeach
            </div>
        </x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::button color="gray" wire:click="cambiarArchivo">Cambiar archivo</x-filament::button>
            @if ($revision['errores'] === [])
                {{ $this->confirmarAction }}
            @endif
        </div>
    @endif
</x-filament-panels::page>
