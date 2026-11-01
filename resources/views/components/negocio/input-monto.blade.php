@props([
    'moneda',
])

<div class="negocio-field flex items-center gap-2">
    <span class="shrink-0 text-xs font-semibold text-gray-400">{{ $moneda }}</span>
    <input
        type="text"
        {{ $attributes->class('negocio-field-control') }}
    />
</div>
