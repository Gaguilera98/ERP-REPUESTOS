@props([
    'ultimoCodigo' => '',
    'ultimoTotal' => '',
    'alertasPerdida' => [],
    'moneda' => 'BOB',
])

@if ($ultimoCodigo !== '')
    <div class="flex items-center justify-between gap-3 rounded-xl border border-emerald-600/30 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-100">
        <div class="flex items-start gap-2">
            <span class="mt-0.5 font-bold">✓</span>
            <div>
                <strong>Última venta: {{ $ultimoCodigo }}</strong>
                @if ($ultimoTotal !== '')
                    <span class="opacity-90"> · Total: {{ $moneda }} {{ $ultimoTotal }}</span>
                @endif
                @if ($alertasPerdida !== [])
                    <div class="mt-1 text-xs opacity-90">Hubo alerta de costo por encima del precio.</div>
                @endif
            </div>
        </div>
        <button type="button" wire:click="limpiarUltimaVenta" class="rounded-lg px-2 py-1 text-xs hover:bg-emerald-100 dark:hover:bg-emerald-400/10">
            Cerrar
        </button>
    </div>
@endif
