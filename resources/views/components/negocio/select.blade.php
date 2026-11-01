@props([
    'searchable' => null,
    'loading' => null,
])

<div
    x-data="negocioSelect({ searchable: @js($searchable) })"
    x-on:click.outside="open = false"
    x-on:keydown.escape.window="open = false"
    class="relative w-full"
>
    <select
        x-ref="native"
        tabindex="-1"
        aria-hidden="true"
        {{ $attributes->whereStartsWith('wire:')->merge($attributes->only(['name', 'id', 'required', 'disabled'])->getAttributes())->class('pointer-events-none absolute h-0 w-0 overflow-hidden opacity-0') }}
        style="display: none"
    >
        {{ $slot }}
    </select>

    <button
        type="button"
        x-ref="trigger"
        {{ $attributes->only('class')->class('negocio-field flex w-full items-center gap-2 text-left') }}
        x-bind:disabled="$refs.native?.disabled"
        x-on:click="toggle()"
    >
        <span class="min-w-0 flex-1 truncate" x-text="label"></span>
        @if (filled($loading))
            <x-negocio.spinner :target="$loading" />
            <svg wire:loading.remove wire:target="{{ $loading }}" class="size-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        @else
            <svg class="size-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        @endif
    </button>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="negocio-select-panel"
    >
        <div x-show="searchable" class="negocio-select-search">
            <input
                x-ref="search"
                type="text"
                x-model="query"
                placeholder="Empezá a escribir para buscar..."
                class="negocio-field-control"
                autocomplete="off"
            />
        </div>
        <div class="negocio-select-options">
            <template x-for="option in filtered" :key="option.value + '|' + option.label">
                <button
                    type="button"
                    class="negocio-select-option"
                    :class="{ 'is-active': option.value === value }"
                    x-on:click="choose(option.value)"
                    x-text="option.label"
                ></button>
            </template>
            <p x-show="filtered.length === 0" class="negocio-select-empty">Nada coincide.</p>
        </div>
    </div>
</div>
