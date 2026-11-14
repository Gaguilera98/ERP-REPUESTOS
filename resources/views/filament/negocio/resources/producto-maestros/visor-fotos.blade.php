<div
    wire:key="visor-fotos-{{ md5(json_encode($fotos, JSON_THROW_ON_ERROR)) }}"
    x-data="{
        fotos: @js($fotos),
        indice: 0,
        mover(paso) {
            this.indice = (this.indice + paso + this.fotos.length) % this.fotos.length
        },
    }"
    class="flex flex-wrap gap-2"
>
    @foreach ($fotos as $foto)
        <button
            type="button"
            aria-label="Ampliar foto {{ $loop->iteration }} de {{ $loop->count }}"
            x-on:click="indice = {{ $loop->index }}; $dispatch('open-modal', { id: 'visor-fotos-producto' })"
            class="relative size-16 cursor-zoom-in overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-950/10 focus-visible:outline-2 focus-visible:outline-primary-500 dark:bg-white/5 dark:ring-white/15"
        >
            <img src="{{ $foto }}" alt="" class="size-full object-contain" loading="lazy">
            <span class="absolute end-1 bottom-1 rounded bg-black/65 p-0.5 text-white">
                <x-filament::icon icon="heroicon-m-magnifying-glass-plus" class="size-4" />
            </span>
        </button>
    @endforeach

    <x-filament::modal
        id="visor-fotos-producto"
        heading="Fotos del producto"
        width="5xl"
        teleport="body"
        x-on:keydown.left.prevent="mover(-1)"
        x-on:keydown.right.prevent="mover(1)"
    >
        <div class="flex flex-col gap-4">
            <img
                x-bind:src="fotos[indice]"
                x-bind:alt="`Foto ${indice + 1} de ${fotos.length}`"
                class="mx-auto max-h-[65dvh] max-w-full object-contain"
            >

            @if (count($fotos) > 1)
                <div class="flex items-center justify-between gap-3">
                    <x-filament::icon-button type="button" color="gray" icon="heroicon-m-chevron-left"
                        label="Foto anterior" x-on:click="mover(-1)" />
                    <span aria-live="polite" class="text-sm tabular-nums text-gray-600 dark:text-gray-300"
                        x-text="`${indice + 1} de ${fotos.length}`"></span>
                    <x-filament::icon-button type="button" color="gray" icon="heroicon-m-chevron-right"
                        label="Foto siguiente" x-on:click="mover(1)" />
                </div>
            @endif
        </div>
    </x-filament::modal>
</div>
