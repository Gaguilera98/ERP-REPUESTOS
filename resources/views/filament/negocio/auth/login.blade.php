<div class="tobora-login grid min-h-dvh md:grid-cols-2">
    <aside
        class="tobora-login-brand flex flex-col items-center justify-center gap-3 px-6 py-8 md:px-10 md:py-12"
        aria-label="Tobora Parts"
        x-data="{
            reducirMovimiento() {
                return window.matchMedia('(prefers-reduced-motion: reduce)').matches
            },
            repetir(escena) {
                if (this.reducirMovimiento()) return

                escena.getAnimations({ subtree: true }).forEach(animacion => {
                    animacion.currentTime = 0
                    animacion.play()
                })
            },
            mover(evento, panel, escena) {
                if (evento.pointerType !== 'mouse' || this.reducirMovimiento()) return

                const limites = panel.getBoundingClientRect()
                const x = (evento.clientX - limites.left) / limites.width - 0.5
                const y = (evento.clientY - limites.top) / limites.height - 0.5

                escena.querySelectorAll('[data-depth]').forEach(pieza => {
                    const escala = Number(pieza.dataset.depth) * -1.4
                    pieza.style.transform = 'translate(' + x * escala + 'px,' + y * escala + 'px)'
                })
            },
            centrar(escena) {
                escena.querySelectorAll('[data-depth]').forEach(pieza => {
                    pieza.style.transform = ''
                })
            },
        }"
        x-on:pointermove="mover($event, $el, $refs.escena)"
        x-on:pointerleave="centrar($refs.escena)"
    >
        <div class="tobora-login-glow" aria-hidden="true"></div>

        <button
            type="button"
            class="tobora-login-stage relative grid aspect-square place-items-center rounded-full"
            aria-label="Repetir animación del logo"
            x-ref="escena"
            x-on:click="repetir($refs.escena)"
        >
            <span class="tobora-login-ring" aria-hidden="true"></span>
            <span class="tobora-login-ring" aria-hidden="true"></span>
            <span class="tobora-login-ring" aria-hidden="true"></span>

            <svg class="tobora-login-logo" viewBox="0 0 512 512" aria-hidden="true" focusable="false">
                <defs>
                    <clipPath id="tobora-login-gear-clip">
                        <path d="M0 305H512V512H0Z"/>
                    </clipPath>
                </defs>
                <g clip-path="url(#tobora-login-gear-clip)">
                    <g class="tobora-login-gear-entry">
                        <g class="tobora-login-gear-spin" fill="none" stroke="var(--tobora-logo-green)" stroke-width="40">
                            <circle cx="259" cy="300" r="156"/>
                            <circle cx="259" cy="300" r="190" stroke-dasharray="62 37.8" stroke-dashoffset="31"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="5">
                    <g class="tobora-login-piece-entry" style="--x:-260px;--y:-120px;--r:-25deg;--d:.1s">
                        <g class="tobora-login-float" style="--fd:0s;--fy:-5px">
                            <path class="tobora-login-piece tobora-login-amber" d="M196 5h66a28 28 0 0 1 28 28v18a22 22 0 0 1-22 22h-6a14 14 0 0 0-14 14v6a22 22 0 0 1-22 22h-34a28 28 0 0 1-28-28V33a28 28 0 0 1 28-28Z"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="8">
                    <g class="tobora-login-piece-entry" style="--x:260px;--y:-200px;--r:30deg;--d:.25s">
                        <g class="tobora-login-float" style="--fd:.8s;--fy:4px">
                            <path class="tobora-login-piece tobora-login-green" d="M340 5h12a22 22 0 0 1 22 22v60a22 22 0 0 1-22 22h-70a18 18 0 0 1-18-18v-4c0-10 8-16 18-18 16-2 22-8 22-24V27a22 22 0 0 1 14-22Z"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="12">
                    <g class="tobora-login-piece-entry" style="--x:-320px;--y:-40px;--r:-18deg;--d:.4s">
                        <g class="tobora-login-float" style="--fd:1.4s;--fy:-4px">
                            <rect class="tobora-login-piece tobora-login-amber" x="66" y="66" width="100" height="90" rx="30"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="20">
                    <g class="tobora-login-piece-entry" style="--x:-40px;--y:-300px;--r:20deg;--d:.55s">
                        <g class="tobora-login-float" style="--fd:.5s;--fy:-3px">
                            <rect class="tobora-login-piece tobora-login-green" x="150" y="128" width="100" height="82" rx="24"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="20">
                    <g class="tobora-login-piece-entry" style="--x:320px;--y:-60px;--r:22deg;--d:.7s">
                        <g class="tobora-login-float" style="--fd:.5s;--fy:-3px">
                            <path class="tobora-login-piece tobora-login-amber" d="M268 150a24 24 0 0 1 24-24h82a8 8 0 0 0 8-8V96a32 32 0 0 1 32-32h30a32 32 0 0 1 32 32v26a44 44 0 0 1-44 44h-16a22 22 0 0 0-22 22v0a26 26 0 0 1-26 26h-72a24 24 0 0 1-24-24Z"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="20">
                    <g class="tobora-login-piece-entry" style="--x:-300px;--y:120px;--r:-30deg;--d:.85s">
                        <g class="tobora-login-float" style="--fd:.5s;--fy:-3px">
                            <path class="tobora-login-piece tobora-login-amber" d="M112 172H38A30 30 0 0 0 8 202V244A30 30 0 0 0 38 274H88A24 24 0 0 0 112 250V243A26 26 0 0 1 130 218.3V190A18 18 0 0 0 112 172Z"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="20">
                    <g class="tobora-login-piece-entry" style="--x:300px;--y:110px;--r:28deg;--d:1s">
                        <g class="tobora-login-float" style="--fd:.5s;--fy:-3px">
                            <path class="tobora-login-piece tobora-login-green" d="M406 172H480A30 30 0 0 1 510 202V244A30 30 0 0 1 480 274H430A24 24 0 0 1 406 250V243A26 26 0 0 0 388 218.3V190A18 18 0 0 1 406 172Z"/>
                        </g>
                    </g>
                </g>

                <g class="tobora-login-depth" data-depth="20">
                    <g class="tobora-login-letter">
                        <g class="tobora-login-float" style="--fd:.5s;--fy:-3px">
                            <path class="tobora-login-piece tobora-login-cream" d="M138 229H380A14 14 0 0 1 394 243V268A14 14 0 0 1 380 282H322C308 282 298 290 298 304C299 318 309 322 307 338C306 356 297 366 296 378C295 390 306 398 314 404Q317 412 309 412H209Q201 412 204 404C212 398 223 390 222 378C221 366 212 356 211 338C209 322 219 318 220 304C220 290 210 282 196 282H138A14 14 0 0 1 124 268V243A14 14 0 0 1 138 229Z"/>
                        </g>
                    </g>
                </g>
            </svg>
        </button>

        <div class="tobora-login-brand-copy relative grid gap-2 text-center">
            <p class="text-3xl font-bold tracking-tight md:text-5xl">Tobora Parts</p>
            <p class="tobora-login-tag hidden text-base md:block">Ventas, inventario y caja en un solo lugar</p>
        </div>
    </aside>

    <main class="tobora-login-main grid place-items-center px-6 py-10 md:px-10 md:py-14" aria-labelledby="tobora-login-title">
        <div class="tobora-login-form w-full max-w-md">
            <header class="mb-8 grid gap-3">
                <h1 id="tobora-login-title" class="text-4xl font-bold tracking-tight md:text-5xl">
                    {{ $this->userUndertakingMultiFactorAuthentication ? $this->getHeading() : 'Iniciá sesión' }}
                </h1>

                @if (! $this->userUndertakingMultiFactorAuthentication)
                    <p class="tobora-login-description text-base md:text-lg">
                        Ingresá a tu cuenta de {{ tenant()?->name ?? 'Tobora Parts' }}.
                    </p>
                @endif
            </header>

            {{ $this->content }}
        </div>
    </main>
</div>
