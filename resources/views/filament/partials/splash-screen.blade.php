{{-- Pantalla de carga (splash) — paneles Filament negocio/admin --}}
<div
    id="tobora-splash"
    role="status"
    aria-live="polite"
    aria-label="Cargando Tobora Parts"
>
    <div class="tobora-splash-inner">
        <img
            src="{{ asset('images/tobora-parts-logo.png') }}"
            alt="Tobora Parts"
            width="180"
            height="64"
            decoding="async"
        />
        <div class="tobora-splash-bar" aria-hidden="true"><span></span></div>
    </div>
</div>

<style>
    #tobora-splash {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #F5F7F5;
        opacity: 1;
        transition: opacity 0.55s ease;
    }
    #tobora-splash.is-hidden {
        opacity: 0;
        pointer-events: none;
    }
    .dark #tobora-splash {
        background: #17232C;
    }
    .tobora-splash-inner {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1.25rem;
    }
    .tobora-splash-inner img {
        width: min(200px, 48vw);
        height: auto;
        display: block;
        animation: tobora-splash-fade 1.6s ease-in-out infinite;
    }
    .tobora-splash-bar {
        width: 72px;
        height: 3px;
        border-radius: 999px;
        background: #E7EBE8;
        overflow: hidden;
    }
    .dark .tobora-splash-bar {
        background: #314556;
    }
    .tobora-splash-bar span {
        display: block;
        width: 40%;
        height: 100%;
        border-radius: inherit;
        background: #174C3A;
        animation: tobora-splash-bar 1.35s ease-in-out infinite;
    }
    .dark .tobora-splash-bar span {
        background: #E99543;
    }
    @keyframes tobora-splash-fade {
        0%, 100% { opacity: 0.72; }
        50% { opacity: 1; }
    }
    @keyframes tobora-splash-bar {
        0% { transform: translateX(-120%); }
        100% { transform: translateX(280%); }
    }
</style>

<script>
    (function () {
        var hidden = false;
        var pageReady = false;
        var shownAt = Date.now();
        var MIN_MS = 850;
        var MAX_MS = 5000;
        var FADE_MS = 550;

        function removeSplash() {
            var el = document.getElementById('tobora-splash');
            if (!el || !el.parentNode) return;
            el.parentNode.removeChild(el);
        }

        function hideSplash() {
            if (hidden) return;
            hidden = true;
            var el = document.getElementById('tobora-splash');
            if (!el) return;
            el.classList.add('is-hidden');
            setTimeout(removeSplash, FADE_MS);
        }

        function tryHide() {
            if (hidden || !pageReady) return;
            var elapsed = Date.now() - shownAt;
            var wait = Math.max(0, MIN_MS - elapsed);
            setTimeout(hideSplash, wait);
        }

        function markReady() {
            pageReady = true;
            tryHide();
        }

        if (document.readyState === 'complete') {
            markReady();
        } else {
            window.addEventListener('load', markReady);
        }

        setTimeout(function () {
            pageReady = true;
            hideSplash();
        }, MAX_MS);

        document.addEventListener('livewire:navigated', function () {
            pageReady = true;
            hideSplash();
        });
    })();
</script>
