@props([
    'target' => null,
])

<svg
    {{ $attributes->class('size-4 shrink-0 animate-spin text-warning-500') }}
    @if (filled($target))
        wire:loading
        wire:target="{{ $target }}"
    @endif
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
>
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-25" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
</svg>
