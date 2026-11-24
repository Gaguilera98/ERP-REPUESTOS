<div class="space-y-3" aria-live="polite">
    <div class="rounded-xl bg-primary-50 p-4 text-sm text-primary-800 dark:bg-primary-500/10 dark:text-primary-200">
        <p class="font-semibold">Se descargará un solo Excel</p>
        <p class="mt-1">Cada producto lleva su categoría. Completá solo los atributos que le correspondan y dejá vacíos los de otras categorías.</p>
    </div>

    @if ($categorias->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Plantilla básica, sin columnas de ficha técnica. Elegí una categoría para incluir sus atributos. Los obligatorios se validarán antes de confirmar la importación.</p>
    @else
        <p class="text-sm font-semibold text-gray-950 dark:text-white">Ficha técnica que se agregará</p>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($categorias as $categoria)
                <div class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <h4 class="font-semibold text-gray-950 dark:text-white">{{ $categoria->nombre }}</h4>
                    <ul class="mt-3 space-y-3 text-sm">
                        @forelse ($categoria->atributos as $atributo)
                            <li class="space-y-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-medium break-words text-gray-950 dark:text-white">{{ $atributo->etiqueta }}</span>
                                    <x-filament::badge :color="$atributo->obligatorio ? 'warning' : 'gray'" size="sm">
                                        {{ $atributo->obligatorio ? 'Obligatorio' : 'Opcional' }}
                                    </x-filament::badge>
                                </div>
                                <p class="text-gray-500 dark:text-gray-400">
                                    {{ $atributo->tipo->etiqueta() }}@if ($atributo->unidadMedida) · {{ $atributo->unidadMedida->simbolo }}@endif
                                </p>
                                @if ($atributo->opciones)
                                    <p class="break-words text-gray-500 dark:text-gray-400">Opciones: {{ implode(' · ', $atributo->opciones) }}</p>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-500 dark:text-gray-400">Esta categoría no tiene atributos configurados.</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
