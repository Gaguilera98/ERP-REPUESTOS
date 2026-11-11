@props([
    'ultimoCodigo' => '',
    'ultimoTotal' => '',
    'ultimoVuelto' => '',
    'mostrarDetalle' => false,
    'alertasPerdida' => [],
    'moneda' => 'BOB',
])

@if ($ultimoCodigo !== '')
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-emerald-600/30 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-100">
        <div class="flex min-w-0 items-center gap-2.5">
            <x-filament::icon icon="heroicon-o-check-circle" class="size-5 shrink-0" />
            <div class="min-w-0">
                <span>Última venta</span>
                <strong class="whitespace-nowrap"> · {{ $ultimoCodigo }}</strong>
                @if ($ultimoTotal !== '')
                    <span class="whitespace-nowrap tabular-nums"> · {{ $moneda }} {{ $ultimoTotal }}</span>
                @endif
                @if ($alertasPerdida !== [])
                    <div class="mt-1 text-xs opacity-90">Hubo alerta de costo por encima del precio.</div>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($ultimoVuelto !== '')
                <div class="inline-flex items-baseline gap-2 rounded-lg bg-emerald-100 px-3 py-1.5 dark:bg-emerald-400/20" role="status">
                    <span class="text-xs font-medium">Vuelto</span>
                    <strong class="whitespace-nowrap text-base tabular-nums">{{ $moneda }} {{ $ultimoVuelto }}</strong>
                </div>
            @endif
            @if ($mostrarDetalle)
                <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-eye" wire:click="mountAction('detalleUltimaVenta')">
                    Ver detalle
                </x-filament::button>
            @endif
            <x-filament::icon-button type="button" color="gray" size="sm" icon="heroicon-m-x-mark" label="Cerrar aviso de última venta" wire:click="limpiarUltimaVenta" />
        </div>
    </div>
@endif
