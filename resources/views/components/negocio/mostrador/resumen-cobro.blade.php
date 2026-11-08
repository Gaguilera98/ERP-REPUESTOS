@props([
    'cajaAbierta',
    'ventaFiada' => false,
    'ventaReserva' => false,
    'tieneCliente' => false,
    'moneda',
    'subtotalBruto',
    'descuentoTotal',
    'totalTentativo',
    'totalRecibido',
    'saldoFavorDisponible' => '0.00',
    'saldoFavorAplicado' => '0.00',
    'permiteReembolsoSenas' => true,
    'diferenciaCobros',
    'lineas' => [],
    'pagos',
    'metodos',
    'tiposDescuento',
    'mostrarDescuentoVenta',
    'tipoDescuentoVenta',
    'comprobantesObligatorios' => [],
    'panel',
])

@php
    $puedeCobrar = $cajaAbierta
        && (($ventaFiada || $ventaReserva)
            ? $tieneCliente && $diferenciaCobros['estado'] === 'falta'
            : $diferenciaCobros['estado'] === 'completo' && (count($pagos) > 0 || (float) $saldoFavorAplicado > 0))
        && (! $ventaReserva || (float) $totalRecibido > 0)
        && (float) $totalTentativo > 0;
    $cantidadProductos = count($lineas);
    $vistaPrevia = 2;
@endphp

<aside class="{{ $panel }} flex min-w-0 flex-col lg:sticky lg:top-4 lg:max-h-[calc(100dvh-5.5rem)]">
    <div class="negocio-hairline flex shrink-0 items-start justify-between gap-3 border-b px-4 py-3">
        <div>
            <div class="font-bold text-gray-950 dark:text-white">Resumen y cobro</div>
            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Confirmá medios y montos.</div>
        </div>
        @if ($cajaAbierta)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">
                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                Caja abierta
            </span>
        @else
            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">
                Sin caja
            </span>
        @endif
    </div>

    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
        <div class="space-y-2">
            <x-negocio.label class="mb-0">Operación</x-negocio.label>
            <div class="grid grid-cols-3 gap-2" role="group" aria-label="Condición de venta">
                <x-filament::button type="button" size="sm" :color="$ventaFiada || $ventaReserva ? 'gray' : 'primary'" :aria-pressed="$ventaFiada || $ventaReserva ? 'false' : 'true'" wire:click="seleccionarOperacion('contado')">
                    Contado
                </x-filament::button>
                <x-filament::button type="button" size="sm" :color="$ventaFiada ? 'primary' : 'gray'" :aria-pressed="$ventaFiada ? 'true' : 'false'" wire:click="seleccionarOperacion('credito')">
                    Crédito
                </x-filament::button>
                <x-filament::button type="button" size="sm" :color="$ventaReserva ? 'primary' : 'gray'" :aria-pressed="$ventaReserva ? 'true' : 'false'" wire:click="seleccionarOperacion('reserva')">Reserva</x-filament::button>
            </div>
        </div>
        @if ($ventaFiada)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $tieneCliente ? 'Podés dejar el cobro vacío o registrar un pago parcial.' : 'Seleccioná un cliente para vender a crédito.' }}
            </p>
        @endif
        @if ($ventaReserva)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $permiteReembolsoSenas ? 'Si la reserva vence, la seña queda a favor y puede reembolsarse con autorización.' : 'Si la reserva vence, la seña queda disponible para otra compra. Este negocio no ofrece reembolso de señas.' }}</p>
            <label for="vence-reserva" class="block text-sm font-medium">Retirar antes de</label>
            <x-filament::input.wrapper><x-filament::input id="vence-reserva" type="datetime-local" wire:model="venceReserva" /></x-filament::input.wrapper>
            <p class="text-xs text-gray-500 dark:text-gray-400">Registrá una seña menor al total para apartar los productos.</p>
            @error('vence_en') <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
        @endif
        @error('cliente_id')
            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
        @enderror
        @if ($tieneCliente && (float) $saldoFavorDisponible > 0)
            <div class="space-y-2 rounded-lg border border-primary-200 p-3 dark:border-primary-400/30">
                <p class="text-sm font-semibold">Saldo a favor disponible · {{ $moneda }} {{ $saldoFavorDisponible }}</p>
                <label for="saldo-favor" class="block text-sm">Usar en esta compra</label>
                <x-negocio.input-monto id="saldo-favor" :moneda="$moneda" wire:model.live.debounce.300ms="saldoFavor" inputmode="decimal" />
                <p class="text-xs text-gray-500 dark:text-gray-400">Este importe se descuenta del saldo del cliente al confirmar.</p>
            </div>
        @endif
        @error('saldo_favor') <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
        <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <x-negocio.label class="mb-0">{{ $ventaFiada ? 'Total de la venta' : 'Total a cobrar' }}</x-negocio.label>
                    <div class="mt-1 text-3xl font-extrabold tracking-tight tabular-nums text-gray-950 dark:text-white">
                        {{ $moneda }} {{ number_format((float) $totalTentativo, 2, '.', ',') }}
                    </div>
                </div>
                <x-filament::icon
                    icon="heroicon-o-shopping-bag"
                    class="mt-0.5 size-6 text-gray-400 dark:text-gray-500"
                />
            </div>
            <div class="mt-3 space-y-1.5 text-sm">
                @if ($ventaFiada || $ventaReserva)
                    <div class="flex justify-between gap-3">
                        <span class="text-gray-500 dark:text-gray-400">Cobrado hoy</span>
                        <span class="tabular-nums">{{ $moneda }} {{ number_format((float) bcsub($totalRecibido, $saldoFavorAplicado, 2), 2, '.', ',') }}</span>
                    </div>
                    <div class="flex justify-between gap-3 font-semibold text-amber-800 dark:text-amber-300">
                        <span>Saldo pendiente</span>
                        <span class="tabular-nums">{{ $moneda }} {{ number_format($diferenciaCobros['estado'] === 'falta' ? (float) $diferenciaCobros['monto'] : 0, 2, '.', ',') }}</span>
                    </div>
                @endif
                <div class="flex justify-between gap-3">
                    <span class="text-gray-500 dark:text-gray-400">Subtotal</span>
                    <span class="tabular-nums">{{ $moneda }} {{ number_format((float) $subtotalBruto, 2, '.', ',') }}</span>
                </div>
                @if ((float) $descuentoTotal > 0)
                    <div class="flex justify-between gap-3">
                        <span class="text-gray-500 dark:text-gray-400">Descuento</span>
                        <span class="tabular-nums text-emerald-700 dark:text-emerald-300">−{{ $moneda }} {{ number_format((float) $descuentoTotal, 2, '.', ',') }}</span>
                    </div>
                @endif
            </div>

            <div class="negocio-hairline mt-3 border-t pt-3" x-data="{ abierto: false }">
                <div class="mb-2 flex items-center justify-between gap-3 text-xs">
                    <span class="font-semibold text-gray-950 dark:text-white">Resumen de productos</span>
                    <span class="tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $cantidadProductos }} {{ $cantidadProductos === 1 ? 'producto' : 'productos' }}
                    </span>
                </div>

                @if ($cantidadProductos === 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400">Todavía no hay productos en la venta.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($lineas as $i => $linea)
                            @php
                                $cantidadTxt = \App\Compras\Montos::sinCeros((string) ($linea['cantidad'] ?? '0'));
                                $unidad = (string) ($linea['unidad_simbolo'] ?? 'pza');
                                $nombreTitulo = \App\Ventas\NombreEnPantalla::titulo($linea['nombre'] ?? '');
                            @endphp
                            <div
                                @if ($i >= $vistaPrevia)
                                    x-show="abierto"
                                    x-cloak
                                @endif
                                class="flex items-start justify-between gap-3"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-xs font-medium leading-snug text-gray-950 dark:text-white">{{ $nombreTitulo }}</div>
                                    <div class="truncate text-[11px] text-gray-400">{{ $linea['sku'] ?: '—' }}</div>
                                </div>
                                <div class="shrink-0 text-xs tabular-nums text-gray-700 dark:text-gray-200">
                                    {{ $cantidadTxt }} {{ $unidad }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($cantidadProductos > $vistaPrevia)
                        <button
                            type="button"
                            class="mt-3 inline-flex w-full items-center justify-center gap-1 text-xs font-semibold text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
                            @click="abierto = !abierto"
                        >
                            <span x-text="abierto ? 'Ocultar detalle' : 'Ver detalle completo'"></span>
                            <span class="inline-flex transition-transform" x-bind:class="abierto ? 'rotate-180' : ''">
                                <x-filament::icon icon="heroicon-m-chevron-down" class="size-3.5" />
                            </span>
                        </button>
                    @endif
                @endif
            </div>
        </div>

        @unless ($cajaAbierta)
            <div class="rounded-lg border border-amber-500/40 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-400/10 dark:text-amber-100">
                Abrí la caja de hoy en esta sucursal para confirmar ventas o reservas.
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <a href="{{ \App\Filament\Negocio\Pages\CajaDelNegocio::getUrl() }}" target="_blank" rel="noopener" class="font-semibold underline">Ir a Caja</a>
                    <button type="button" wire:click="$refresh" class="font-semibold underline">Ya abrí mi caja</button>
                </div>
            </div>
        @endunless

        @if ($mostrarDescuentoVenta)
            <div class="negocio-inset p-3">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <div class="inline-flex items-center gap-1.5 font-semibold text-gray-950 dark:text-white">
                        <x-filament::icon icon="heroicon-m-receipt-percent" class="size-4 text-gray-400 dark:text-gray-500" />
                        Descuento de la venta
                    </div>
                    <button type="button" wire:click="quitarDescuentoVenta" class="text-xs font-semibold text-danger-600 hover:underline">Quitar</button>
                </div>
                <div class="grid gap-2">
                    <x-negocio.select wire:model.live="tipoDescuentoVenta">
                        @foreach ($tiposDescuento as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->etiqueta() }}</option>
                        @endforeach
                    </x-negocio.select>
                    @if ($tipoDescuentoVenta === 'porcentaje')
                        <div class="negocio-field flex items-center gap-2">
                            <input
                                type="text"
                                wire:model.live="valorDescuentoVenta"
                                inputmode="decimal"
                                placeholder="0"
                                class="negocio-field-control min-w-0"
                            />
                            <span class="shrink-0 text-xs font-semibold text-gray-400">%</span>
                        </div>
                    @else
                        <x-negocio.input-monto
                            :moneda="$moneda"
                            wire:model.live="valorDescuentoVenta"
                            inputmode="decimal"
                            placeholder="0,00"
                        />
                    @endif
                </div>
            </div>
        @else
            <x-filament::button
                type="button"
                size="xs"
                color="gray"
                icon="heroicon-m-receipt-percent"
                wire:click="mostrarCampoDescuentoVenta"
            >
                Agregar descuento
            </x-filament::button>
        @endif

        <div>
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="inline-flex items-center gap-1.5 font-semibold text-gray-950 dark:text-white">
                    <x-filament::icon icon="heroicon-m-banknotes" class="size-4 text-gray-400 dark:text-gray-500" />
                    Métodos de pago
                </div>
                <x-filament::button type="button" size="xs" color="gray" icon="heroicon-m-plus" wire:click="agregarPago">
                    Método
                </x-filament::button>
            </div>

            <div class="space-y-3">
                @foreach ($pagos as $i => $pago)
                    @php
                        $metodo = $pago['metodo_pago'] ?? '';
                        $metodoEnum = \App\Enums\MetodoPago::tryFrom($metodo);
                        $pideRef = in_array($metodo, ['transferencia', 'qr_externo', 'voucher_tarjeta'], true);
                        $pideAdjunto = $metodoEnum?->aceptaComprobanteArchivo() ?? false;
                        $adjuntoObligatorio = (bool) ($comprobantesObligatorios[$metodo] ?? false);
                        $iconoMetodo = match ($metodo) {
                            'efectivo' => 'heroicon-m-banknotes',
                            'qr_externo' => 'heroicon-m-qr-code',
                            'voucher_tarjeta' => 'heroicon-m-credit-card',
                            'transferencia' => 'heroicon-m-arrows-right-left',
                            default => 'heroicon-m-banknotes',
                        };
                    @endphp
                    <div wire:key="pago-{{ $i }}-{{ $metodo }}" class="negocio-inset p-3">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <div class="inline-flex items-center gap-1.5">
                                <x-filament::icon :icon="$iconoMetodo" class="size-4 text-gray-400 dark:text-gray-500" />
                                <x-negocio.label class="mb-0">Método {{ $i + 1 }}</x-negocio.label>
                            </div>
                            @if (count($pagos) > 1)
                                <button type="button" wire:click="quitarPago({{ $i }})" class="text-xs font-semibold text-danger-600 hover:underline">Quitar</button>
                            @endif
                        </div>
                        <div class="grid gap-2">
                            <x-negocio.select wire:model.live="pagos.{{ $i }}.metodo_pago">
                                @foreach ($metodos as $metodoPago)
                                    <option value="{{ $metodoPago->value }}" @selected($metodo === $metodoPago->value)>{{ $metodoPago->etiqueta() }}</option>
                                @endforeach
                            </x-negocio.select>
                            <x-negocio.input-monto
                                :moneda="$moneda"
                                wire:model.live="pagos.{{ $i }}.monto"
                                inputmode="decimal"
                                placeholder="0,00"
                            />
                            @error('pagos.'.$i.'.monto')
                                <p class="text-xs font-semibold text-danger-600">{{ $message }}</p>
                            @enderror
                            @if ($pideRef)
                                <x-negocio.input
                                    wire:model="pagos.{{ $i }}.numero_referencia_banco"
                                    placeholder="Referencia / voucher"
                                />
                                @error('pagos.'.$i.'.numero_referencia_banco')
                                    <p class="text-xs font-semibold text-danger-600">{{ $message }}</p>
                                @enderror
                            @endif
                            @if ($metodo === 'qr_externo')
                                <x-negocio.input
                                    wire:model="pagos.{{ $i }}.qr_id_transaccion_banco"
                                    placeholder="ID transacción QR"
                                />
                                @error('pagos.'.$i.'.qr_id_transaccion_banco')
                                    <p class="text-xs font-semibold text-danger-600">{{ $message }}</p>
                                @enderror
                            @endif
                            @if ($pideAdjunto)
                                <div wire:key="adjunto-{{ $i }}-{{ $metodo }}">
                                    <x-negocio.label>
                                        Adjunto
                                        @if ($adjuntoObligatorio)
                                            <span class="text-warning-600">*</span>
                                        @endif
                                        <span class="font-normal normal-case tracking-normal">(PDF o imagen)</span>
                                    </x-negocio.label>
                                    <input
                                        type="file"
                                        accept=".pdf,image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                                        wire:model="pagos.{{ $i }}.comprobante"
                                        class="block w-full text-xs text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-gray-700 hover:file:bg-gray-200 dark:file:bg-white/10 dark:file:text-gray-200"
                                    />
                                    @error('pagos.'.$i.'.comprobante')
                                        <p class="mt-1 text-xs font-semibold text-danger-600">{{ $message }}</p>
                                    @enderror
                                    @if (! empty($pago['comprobante']))
                                        <div class="mt-1 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">✓ Archivo listo</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="negocio-inset p-3">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="text-gray-500 dark:text-gray-400">{{ (float) $saldoFavorAplicado > 0 ? 'Total aplicado a la compra' : 'Total recibido' }}</span>
                <span class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ number_format((float) $totalRecibido, 2, '.', ',') }}</span>
            </div>
            <div class="mt-2">
                @if ($diferenciaCobros['estado'] === 'completo' && (float) $totalTentativo > 0)
                    <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">Completo</span>
                @elseif ($diferenciaCobros['estado'] === 'falta')
                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-900 dark:bg-amber-400/15 dark:text-amber-200">
                        {{ $ventaFiada ? 'Saldo pendiente' : 'Falta' }} {{ $moneda }} {{ number_format((float) $diferenciaCobros['monto'], 2, '.', ',') }}
                    </span>
                @elseif ($diferenciaCobros['estado'] === 'sobra')
                    <span class="inline-flex rounded-full bg-sky-100 px-2.5 py-1 text-[11px] font-bold text-sky-900 dark:bg-sky-400/15 dark:text-sky-200">
                        Sobra {{ $moneda }} {{ number_format((float) $diferenciaCobros['monto'], 2, '.', ',') }}
                    </span>
                @else
                    <span class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">Sin montos</span>
                @endif
            </div>
        </div>

        <div class="space-y-1.5 text-[11px] leading-snug text-gray-500 dark:text-gray-400">
            <p>El efectivo se registra en la caja abierta de esta sucursal.</p>
            <p>Transferencia y voucher pueden llevar referencia y adjunto.</p>
        </div>
    </div>

    <div class="{{ $panel }} negocio-hairline shrink-0 border-t p-4 max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-30 max-lg:rounded-none max-lg:pb-[max(1rem,env(safe-area-inset-bottom))]">
        <div class="mb-2 flex items-center justify-between gap-3 text-sm lg:hidden">
            <span class="text-gray-500 dark:text-gray-400">Total</span>
            <span class="font-bold tabular-nums text-gray-950 dark:text-white">{{ $moneda }} {{ number_format((float) $totalTentativo, 2, '.', ',') }}</span>
        </div>
        <x-filament::button
            type="button"
            color="warning"
            size="lg"
            icon="heroicon-m-banknotes"
            class="w-full justify-center"
            wire:click="completar"
            wire:loading.attr="disabled"
            :disabled="! $puedeCobrar"
        >
            <span wire:loading.remove wire:target="completar">{{ $ventaReserva ? 'Confirmar reserva' : ($ventaFiada ? 'Registrar venta a crédito' : 'Cobrar y completar') }}</span>
            <span wire:loading wire:target="completar">Procesando…</span>
        </x-filament::button>
    </div>
</aside>
