@props([
    'type' => 'text',
])

<input
    type="{{ $type }}"
    {{ $attributes->class('negocio-field') }}
/>
