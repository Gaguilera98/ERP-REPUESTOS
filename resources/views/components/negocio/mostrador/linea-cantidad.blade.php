<div class="negocio-hairline inline-flex items-center rounded-md border {{ $pasaStock ? 'border-amber-500/60' : '' }}">
    <button type="button" wire:click="cambiarCantidad({{ $i }}, -1)" class="px-1.5 py-0.5 text-xs font-bold hover:bg-gray-50 dark:hover:bg-white/5">−</button>
    <input
        wire:model.live="lineas.{{ $i }}.cantidad"
        wire:blur="compactarCantidadVisible({{ $i }})"
        class="negocio-hairline w-9 border-x bg-transparent px-0.5 py-0.5 text-center text-xs tabular-nums outline-none {{ $pasaStock ? 'font-bold text-amber-800 dark:text-amber-200' : '' }}"
    />
    <button type="button" wire:click="cambiarCantidad({{ $i }}, 1)" class="px-1.5 py-0.5 text-xs font-bold hover:bg-gray-50 dark:hover:bg-white/5">+</button>
</div>
