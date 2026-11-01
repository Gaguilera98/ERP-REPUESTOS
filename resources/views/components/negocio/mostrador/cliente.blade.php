@props([
    'mostrar' => false,
    'registrando' => false,
    'busqueda' => '',
    'resultados' => [],
    'puedeRegistrar' => false,
    'tipo' => 'persona_natural',
])

@if ($mostrar)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" wire:click.self="cerrarCliente">
        <div class="negocio-modal w-full max-w-lg" role="dialog" aria-modal="true">
            <div class="negocio-hairline flex items-start justify-between gap-3 border-b px-5 py-4">
                <div class="flex items-center gap-2">
                    <svg class="size-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.118a7.5 7.5 0 0 1 15 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.5-1.632Z" />
                    </svg>
                    <div class="text-lg font-bold text-gray-950 dark:text-white">
                        {{ $registrando ? 'Registrar nuevo cliente' : 'Buscar o registrar cliente' }}
                    </div>
                </div>
                <button type="button" wire:click="cerrarCliente" class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/10">✕</button>
            </div>

            @if ($registrando)
                <div class="grid gap-3 px-5 py-4">
                    <label class="block">
                        <x-negocio.label>Tipo de cliente <span class="text-warning-600">*</span></x-negocio.label>
                        <x-negocio.select wire:model.live="clienteTipo" :searchable="false">
                            @foreach (\App\Enums\TipoCliente::cases() as $tipoCliente)
                                <option value="{{ $tipoCliente->value }}">{{ $tipoCliente->etiqueta() }}</option>
                            @endforeach
                        </x-negocio.select>
                    </label>

                    @if ($tipo === \App\Enums\TipoCliente::Empresa->value)
                        <label class="block">
                            <x-negocio.label>Razón social <span class="text-warning-600">*</span></x-negocio.label>
                            <x-negocio.input wire:model="clienteRazonSocial" placeholder="Taller Norte SRL" autocomplete="off" />
                        </label>
                    @else
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <x-negocio.label>Nombre <span class="text-warning-600">*</span></x-negocio.label>
                                <x-negocio.input wire:model="clienteNombres" placeholder="Luis" autocomplete="off" />
                            </label>
                            <label class="block">
                                <x-negocio.label>Apellidos <span class="text-warning-600">*</span></x-negocio.label>
                                <x-negocio.input wire:model="clienteApellidos" placeholder="Pérez Gómez" autocomplete="off" />
                            </label>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <x-negocio.label>
                                {{ $tipo === \App\Enums\TipoCliente::Empresa->value ? 'NIT' : 'CI / NIT' }}
                                <span class="text-warning-600">*</span>
                            </x-negocio.label>
                            <x-negocio.input wire:model="clienteDocumento" placeholder="8765432" autocomplete="off" />
                        </label>
                        <label class="block">
                            <x-negocio.label>Teléfono <span class="text-warning-600">*</span></x-negocio.label>
                            <x-negocio.input wire:model="clienteTelefono" inputmode="tel" placeholder="71345678" autocomplete="off" />
                        </label>
                    </div>
                    <label class="block">
                        <x-negocio.label>Correo <span class="font-normal normal-case tracking-normal">(opcional)</span></x-negocio.label>
                        <x-negocio.input type="email" wire:model="clienteEmail" placeholder="correo@ejemplo.com" autocomplete="off" />
                    </label>
                </div>
                <div class="negocio-hairline flex justify-end gap-2 border-t px-5 py-3">
                    <x-filament::button type="button" color="gray" wire:click="volverBusquedaCliente">Cancelar</x-filament::button>
                    <x-filament::button type="button" color="warning" wire:click="registrarClienteRapido">
                        Registrar y seleccionar
                    </x-filament::button>
                </div>
            @else
                <div class="space-y-3 px-5 py-4">
                    <div class="negocio-field flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-m-magnifying-glass"
                            wire:loading.remove
                            wire:target="busquedaCliente"
                            class="size-4 shrink-0 text-gray-400"
                        />
                        <x-negocio.spinner target="busquedaCliente" />
                        <input
                            type="text"
                            wire:model.live.debounce.500ms="busquedaCliente"
                            placeholder="Nombre, CI, NIT, teléfono o correo"
                            autocomplete="off"
                            class="negocio-field-control"
                        />
                    </div>

                    @if (trim((string) $busqueda) !== '')
                        <div class="negocio-inset overflow-hidden" wire:loading.class="opacity-60" wire:target="busquedaCliente">
                            @forelse ($resultados as $resultado)
                                <div wire:key="cliente-{{ $resultado['id'] }}" class="flex items-center justify-between gap-3 px-3 py-2.5">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $resultado['nombre'] }}</div>
                                        <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $resultado['documento'] !== '' ? $resultado['documento'] : 'Sin documento' }}
                                            @if ($resultado['telefono'] !== '')
                                                · {{ $resultado['telefono'] }}
                                            @endif
                                        </div>
                                    </div>
                                    <x-filament::button type="button" size="xs" color="gray" wire:click="seleccionarCliente({{ $resultado['id'] }})">
                                        Seleccionar
                                    </x-filament::button>
                                </div>
                            @empty
                                <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    No encontramos a “{{ $busqueda }}”.
                                </div>
                            @endforelse
                        </div>
                    @else
                        <div class="px-1 text-sm text-gray-500 dark:text-gray-400">
                            Escribí un dato para buscar coincidencias.
                        </div>
                    @endif
                </div>
                @if ($puedeRegistrar)
                    <div class="negocio-hairline flex justify-end border-t px-5 py-3">
                        <x-filament::button type="button" color="warning" wire:click="abrirRegistroCliente">
                            + Registrar nuevo cliente
                        </x-filament::button>
                    </div>
                @endif
            @endif
        </div>
    </div>
@endif
