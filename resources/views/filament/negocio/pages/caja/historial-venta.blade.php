<section class="order-5 rounded-lg border border-gray-200 dark:border-white/10" x-data="{ mostrarTodo: false }">
    <h3 class="flex items-center gap-2 px-4 pt-4 pb-1 text-sm font-semibold text-gray-950 dark:text-white">
        <x-filament::icon icon="heroicon-o-clock" class="size-4 text-gray-500 dark:text-gray-400" />
        Lo que pasó
    </h3>

    <ol class="divide-y divide-gray-200 px-4 dark:divide-white/10">
        @foreach ($historialVenta as $evento)
            <li @if ($loop->index >= 4) x-show="mostrarTodo" x-cloak @endif
                class="grid gap-x-3 gap-y-1.5 py-3 text-sm sm:grid-cols-[7rem_minmax(0,1fr)]">
                <time datetime="{{ $evento['fecha']->toIso8601String() }}" class="pt-0.5 text-xs whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">
                    {{ $evento['fecha']->format('d/m/Y') }} · {{ $evento['fecha']->format('H:i') }}
                </time>
                <div class="flex min-w-0 items-start gap-2.5">
                    <x-filament::icon :icon="$evento['icono']" class="mt-0.5 size-4 shrink-0 text-gray-500 dark:text-gray-400" />
                    <div class="min-w-0 space-y-1 wrap-break-word">
                        <p class="font-semibold text-gray-950 dark:text-white">{{ $evento['titulo'] }}</p>
                        @if (filled($evento['responsable']))
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $evento['responsable'] }}</p>
                        @endif
                        @if (filled($evento['motivo']))
                            <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $evento['motivo'] }}</p>
                        @endif
                        @foreach ($evento['detalles'] as $detalle)
                            <p class="text-xs text-gray-600 dark:text-gray-300">{{ $detalle }}</p>
                        @endforeach
                    </div>
                </div>
            </li>
        @endforeach
    </ol>

    @if ($historialVenta->count() > 4)
        <div class="border-t border-gray-200 px-4 py-2 dark:border-white/10">
            <button type="button" x-on:click="mostrarTodo = !mostrarTodo" x-bind:aria-expanded="mostrarTodo"
                class="inline-flex min-h-8 items-center gap-1.5 rounded text-sm font-medium text-primary-700 hover:underline focus-visible:outline-2 focus-visible:outline-primary-500 dark:text-primary-300">
                <span x-show="!mostrarTodo">Ver actividad anterior ({{ $historialVenta->count() - 4 }})</span>
                <span x-show="mostrarTodo" x-cloak>Mostrar menos</span>
                <x-filament::icon icon="heroicon-o-chevron-down" class="size-4 transition-transform" x-bind:class="{ 'rotate-180': mostrarTodo }" />
            </button>
        </div>
    @endif
</section>
