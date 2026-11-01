@php
    $input = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-950 shadow-sm outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-white/10 dark:bg-gray-950 dark:text-white dark:focus:border-primary-400';
    $panel = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $mini = 'text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400';
@endphp

<div class="mx-auto w-full max-w-[1600px] space-y-5">
    @if ($ultimoCodigo !== '')
        <div class="flex items-center justify-between gap-3 rounded-xl border border-emerald-600/30 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-100">
            <div>
                <strong>Venta completada correctamente.</strong>
                <div class="mt-1 text-xs opacity-90">
                    Última venta: {{ $ultimoCodigo }}
                    @if ($alertasPerdida !== [])
                        · hay alerta de costo por encima del precio
                    @endif
                </div>
            </div>
            <button type="button" wire:click="$set('ultimoCodigo', '')" class="rounded-lg px-2 py-1 text-xs hover:bg-emerald-100 dark:hover:bg-emerald-400/10">
                Cerrar
            </button>
        </div>
    @endif

    @if (! $cajaAbierta)
        <div class="flex items-start gap-3 rounded-xl border border-amber-600/40 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-amber-100">
            <span class="text-lg leading-none">⚠</span>
            <div>
                <strong>Abrí la caja en esa sucursal antes de cobrar.</strong>
                <div class="mt-1 text-xs opacity-90">
                    El mostrador exige turno abierto. Podés armar el carrito, pero no completar la venta.
                </div>
            </div>
        </div>
    @endif

    <section class="{{ $panel }} p-4">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div class="text-sm font-bold text-gray-950 dark:text-white">Contexto de la venta</div>
            <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">CONTADO</span>
        </div>
        <div class="grid gap-3 md:grid-cols-3">
            <label class="block">
                <span class="{{ $mini }} mb-1.5 block">Sucursal <span class="text-warning-600">*</span></span>
                <select wire:model.live="sucursalId" class="{{ $input }}">
                    @foreach ($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}">{{ $sucursal->nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $mini }} mb-1.5 block">Almacén <span class="text-warning-600">*</span></span>
                <select wire:model.live="almacenId" class="{{ $input }}">
                    @foreach ($almacenes as $almacen)
                        <option value="{{ $almacen->id }}">{{ $almacen->nombre }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $mini }} mb-1.5 block">Cliente <span class="font-normal normal-case tracking-normal">(opcional)</span></span>
                <select wire:model="clienteId" class="{{ $input }}">
                    <option value="">Mostrador · Consumidor final</option>
                    @foreach ($clientes as $cliente)
                        <option value="{{ $cliente->id }}">{{ $cliente->razon_social }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-12">
        <div class="min-w-0 space-y-5 xl:col-span-8">
            <section class="{{ $panel }} p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <div class="font-bold text-gray-950 dark:text-white">Buscar productos</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Escaneá un código o escribí el SKU o nombre.</div>
                    </div>
                </div>
                <form wire:submit="buscarYAgregar" class="flex gap-2">
                    <input
                        wire:model="busqueda"
                        type="text"
                        placeholder="SKU, código de barras o nombre"
                        class="{{ $input }} !py-3"
                        autofocus
                    />
                    <x-filament::button type="submit" color="warning" class="shrink-0">
                        Agregar
                    </x-filament::button>
                </form>
                <div class="mt-3">
                    <button type="button" wire:click="abrirFlashNuevo" class="text-xs text-primary-600 hover:underline dark:text-primary-300">
                        Entrada express
                    </button>
                </div>
            </section>

            <section class="{{ $panel }} overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div>
                        <div class="font-bold text-gray-950 dark:text-white">Detalle de la venta</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ count($lineas) }} {{ count($lineas) === 1 ? 'producto agregado' : 'productos agregados' }}
                        </div>
                    </div>
                    @if (count($lineas) > 0)
                        <button type="button" wire:click="vaciarCarrito" class="text-xs text-gray-500 hover:text-danger-600 dark:text-gray-400">
                            Vaciar carrito
                        </button>
                    @endif
                </div>

                @if (count($lineas) === 0)
                    <div class="px-5 py-12 text-center">
                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-2xl dark:bg-white/5">⌕</div>
                        <div class="font-semibold text-gray-950 dark:text-white">Buscá una pieza para empezar.</div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Podés usar el SKU, código de barras o nombre del producto.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">Producto</th>
                                    <th class="px-2 py-3 text-center font-semibold">Cant.</th>
                                    <th class="px-2 py-3 text-start font-semibold">Desc.</th>
                                    <th class="px-4 py-3 text-end font-semibold">Precio</th>
                                    <th class="px-2 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($lineas as $i => $linea)
                                    @php
                                        $costoAlto = isset($linea['precio_costo'])
                                            && is_numeric($linea['precio_costo'])
                                            && is_numeric($linea['precio_unitario'])
                                            && (float) $linea['precio_costo'] > (float) $linea['precio_unitario'];
                                    @endphp
                                    <tr class="border-t border-gray-100 dark:border-white/5" wire:key="linea-{{ $i }}-{{ $linea['producto_variante_id'] }}">
                                        <td class="min-w-[220px] px-4 py-3">
                                            <div class="text-xs font-bold text-primary-600 dark:text-primary-300">{{ $linea['sku'] }}</div>
                                            <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $linea['nombre'] }}</div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                Disp. {{ $linea['disponible'] }}
                                                @if ($costoAlto)
                                                    <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">Costo alto</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-2 py-3 text-center">
                                            <input wire:model.live="lineas.{{ $i }}.cantidad" type="text" class="{{ $input }} mx-auto w-[68px] !px-2 !py-1.5 text-center" />
                                        </td>
                                        <td class="px-2 py-3">
                                            <div class="flex flex-col gap-1">
                                                <select wire:model.live="lineas.{{ $i }}.tipo_descuento" class="{{ $input }} !px-2 !py-1 text-xs">
                                                    <option value="">Sin desc.</option>
                                                    @foreach ($tiposDescuento as $tipo)
                                                        <option value="{{ $tipo->value }}">{{ $tipo->etiqueta() }}</option>
                                                    @endforeach
                                                </select>
                                                <input wire:model.live="lineas.{{ $i }}.valor_descuento" type="text" class="{{ $input }} w-20 !px-2 !py-1 text-xs" placeholder="0" />
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end">
                                            <div class="font-semibold text-gray-950 dark:text-white">{{ $moneda }} {{ $linea['precio_unitario'] }}</div>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $moneda }} {{ $this->totalDeLinea($linea) }} total</div>
                                        </td>
                                        <td class="px-2 py-3">
                                            <button type="button" wire:click="quitarLinea({{ $i }})" class="grid h-8 w-8 place-items-center rounded-lg text-gray-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10" title="Quitar línea">
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap justify-between gap-2 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                        <span>Los descuentos se aplican por línea.</span>
                        <span>{{ rtrim(rtrim($unidadesEnCarrito, '0'), '.') }} unidades en total</span>
                    </div>
                @endif
            </section>

            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">Regla de descuento</span>
                <span>Si supera el límite del negocio, requiere autorización de supervisor.</span>
            </div>
        </div>

        <aside class="{{ $panel }} sticky top-20 space-y-4 p-4 xl:col-span-4">
            <div class="flex items-center justify-between">
                <div class="font-bold text-gray-950 dark:text-white">Resumen y cobro</div>
                @if ($cajaAbierta)
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-bold text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200">Caja abierta</span>
                @else
                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">Caja cerrada</span>
                @endif
            </div>

            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                <div class="{{ $mini }}">Total a cobrar</div>
                <div class="mt-1 text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                    {{ $moneda }} {{ $totalTentativo }}
                </div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Subtotal de productos · {{ $moneda }} {{ $totalTentativo }}
                </div>
            </div>

            <div>
                <div class="mb-3 flex items-center justify-between">
                    <div class="text-sm font-bold text-gray-950 dark:text-white">Medios de cobro</div>
                    <button type="button" wire:click="agregarPago" class="text-xs font-semibold text-primary-600 dark:text-primary-300">
                        + Otro cobro
                    </button>
                </div>

                <div class="space-y-3">
                    @foreach ($pagos as $i => $pago)
                        <div class="rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10" wire:key="pago-{{ $i }}">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="{{ $mini }}">Cobro {{ $i + 1 }}</span>
                                @if (count($pagos) > 1)
                                    <button type="button" wire:click="quitarPago({{ $i }})" class="text-xs text-danger-600">Quitar</button>
                                @endif
                            </div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400">
                                Método
                                <select wire:model.live="pagos.{{ $i }}.metodo_pago" class="{{ $input }} mt-1 !py-2">
                                    @foreach ($metodos as $metodo)
                                        <option value="{{ $metodo->value }}">{{ $metodo->etiqueta() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <label class="block text-xs text-gray-500 dark:text-gray-400">
                                    Monto
                                    <input wire:model.live="pagos.{{ $i }}.monto" type="text" class="{{ $input }} mt-1 !py-2" placeholder="0.00" />
                                </label>
                                <label class="block text-xs text-gray-500 dark:text-gray-400">
                                    Referencia / voucher
                                    <input
                                        wire:model="pagos.{{ $i }}.numero_referencia_banco"
                                        type="text"
                                        class="{{ $input }} mt-1 !py-2"
                                        placeholder="{{ in_array($pago['metodo_pago'] ?? '', ['transferencia', 'voucher_tarjeta'], true) ? 'Obligatoria' : 'Opcional' }}"
                                    />
                                </label>
                            </div>
                            <label class="mt-2 block text-xs text-gray-500 dark:text-gray-400">
                                Comprobante
                                <input wire:model="pagos.{{ $i }}.comprobante" type="file" class="mt-1 w-full text-xs" />
                            </label>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Total recibido</div>
                        <div class="font-bold text-gray-950 dark:text-white">{{ $moneda }} {{ $totalRecibido }}</div>
                    </div>
                    <div class="text-end">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Diferencia</div>
                        @if ($diferenciaCobros['estado'] === 'completo')
                            <div class="font-bold text-emerald-600 dark:text-emerald-300">Completo</div>
                        @elseif ($diferenciaCobros['estado'] === 'falta')
                            <div class="font-bold text-amber-600 dark:text-amber-300">- {{ $moneda }} {{ $diferenciaCobros['monto'] }}</div>
                        @else
                            <div class="font-bold text-amber-600 dark:text-amber-300">+ {{ $moneda }} {{ $diferenciaCobros['monto'] }}</div>
                        @endif
                    </div>
                </div>

                @if ($diferenciaCobros['estado'] === 'completo')
                    <p class="mt-2 text-xs text-emerald-600 dark:text-emerald-300">Los cobros coinciden con el total.</p>
                @elseif ($diferenciaCobros['estado'] === 'falta')
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-300">Falta {{ $moneda }} {{ $diferenciaCobros['monto'] }}</p>
                @else
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-300">Sobran {{ $moneda }} {{ $diferenciaCobros['monto'] }}</p>
                @endif
            </div>

            <div class="space-y-2 border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                <div class="flex items-start gap-2">
                    <span class="text-primary-500">ⓘ</span>
                    <span>El efectivo ingresa a la caja del turno. QR, tarjeta y transferencia no modifican el efectivo teórico.</span>
                </div>
                <div class="flex items-start gap-2">
                    <span class="text-primary-500">ⓘ</span>
                    <span>El comprobante adjunto es opcional en esta fase.</span>
                </div>
            </div>

            <x-filament::button wire:click="completar" color="warning" size="lg" class="w-full !py-3.5">
                Cobrar y completar
            </x-filament::button>
            <div class="text-center text-[11px] text-gray-500 dark:text-gray-400">Revisá los importes antes de confirmar.</div>
        </aside>
    </div>

    @if ($mostrarFlash)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4" wire:click.self="$set('mostrarFlash', false)">
            <div class="{{ $panel }} w-full max-w-lg space-y-4 p-5 shadow-2xl" @click.stop>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="{{ $mini }}">Ingreso rápido de mercadería</div>
                        <h2 class="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">Entrada express</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Confirmá el costo por unidad. Se usa el proveedor ocasional.</p>
                    </div>
                    <button type="button" wire:click="$set('mostrarFlash', false)" class="rounded-lg px-2 py-1 text-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5">✕</button>
                </div>

                @if ($flashVarianteId)
                    <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                        <div class="{{ $mini }}">Producto existente</div>
                        <div class="mt-1 font-bold text-gray-950 dark:text-white">{{ $flashNombre }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">SKU: {{ $flashSku }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Cantidad
                            <input wire:model="flashCantidad" type="text" class="{{ $input }} mt-1" />
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Costo por unidad
                            <input wire:model="flashCosto" type="text" class="{{ $input }} mt-1" placeholder="0.00" />
                        </label>
                    </div>
                @else
                    <label class="block text-xs text-gray-500 dark:text-gray-400">
                        Nombre del producto *
                        <input wire:model="flashNombre" type="text" class="{{ $input }} mt-1" placeholder="Ej. Rodamiento 6205" />
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            SKU (opcional)
                            <input wire:model="flashSku" type="text" class="{{ $input }} mt-1" />
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Precio de venta
                            <input wire:model="flashPrecioVenta" type="text" class="{{ $input }} mt-1" placeholder="0.00" />
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Cantidad
                            <input wire:model="flashCantidad" type="text" class="{{ $input }} mt-1" />
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400">
                            Costo por unidad
                            <input wire:model="flashCosto" type="text" class="{{ $input }} mt-1" placeholder="0.00" />
                        </label>
                    </div>
                @endif

                <label class="flex items-start gap-2 rounded-lg bg-gray-50 p-3 text-sm ring-1 ring-gray-950/5 dark:bg-gray-950 dark:ring-white/10">
                    <input wire:model="flashConfirmo" type="checkbox" class="mt-1 rounded border-gray-300 text-warning-600 focus:ring-warning-500" />
                    <span>Confirmo que el costo por unidad ingresado es correcto.</span>
                </label>

                <div class="flex justify-end gap-2 pt-1">
                    <x-filament::button color="gray" wire:click="$set('mostrarFlash', false)">Cancelar</x-filament::button>
                    <x-filament::button color="warning" wire:click="ejecutarFlash">Cargar y agregar</x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
