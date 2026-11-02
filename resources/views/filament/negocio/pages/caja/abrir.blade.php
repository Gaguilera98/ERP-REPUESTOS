{{-- Vista: sin sesión — Abrir caja (estructura del mock, componentes Filament) --}}
<section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="space-y-6 p-6 sm:p-8">
        <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
            <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-300">
                <x-filament::icon
                    icon="heroicon-o-banknotes"
                    class="size-7"
                />
            </div>
            <div class="min-w-0">
                <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                    Abrir caja
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Con el monto del cambio del cajón listo, abrí el turno.
                </p>
            </div>
        </div>

        <div class="max-w-xl space-y-1">
            {{ $this->form }}
        </div>

        <div class="flex justify-center pt-1">
            <x-filament::button
                type="submit"
                color="primary"
                size="lg"
                icon="heroicon-m-lock-open"
                class="min-w-56"
            >
                Abrir caja
            </x-filament::button>
        </div>
    </div>
</section>
