<div wire:loading.block wire:target="data.almacen_destino_id" role="status">
    <div class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 dark:border-white/10 dark:text-gray-300">
        {{ \Filament\Support\generate_loading_indicator_html() }}
        <span>Cargando productos…</span>
    </div>
</div>
