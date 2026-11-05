<style>
    .fi-sidebar {
        background-color: #174C3A;
    }

    .fi-sidebar .fi-logo,
    .fi-sidebar .fi-sidebar-item-label,
    .fi-sidebar .fi-sidebar-group-label {
        color: #F5F7F5;
    }

    .fi-sidebar .fi-sidebar-item-btn > .fi-icon,
    .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn .fi-icon {
        color: #D5DDD8;
    }

    .fi-sidebar .fi-sidebar-item-btn:hover,
    .fi-sidebar .fi-sidebar-item-btn:focus-visible,
    .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:hover,
    .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:focus-visible {
        background-color: rgb(46 125 99 / 0.45);
    }

    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background-color: #2E7D63;
    }

    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label,
    .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: #F5F7F5;
    }

    .fi-sidebar .fi-sidebar-header-controls::after,
    .fi-sidebar .fi-sidebar-footer {
        border-color: rgb(245 247 245 / 0.18);
    }

    .dark {
        --gray-800: #1E2529;
        --gray-900: #1E2529;
        --gray-950: #111517;
        --color-gray-200: #F3F4F6;
        --gray-300: #B3BEC4;
        --gray-400: #B3BEC4;
        --gray-500: #94A3A8;
        --gray-700: #354147;
        --color-gray-300: #B3BEC4;
        --color-gray-700: #354147;
        --color-gray-400: #B3BEC4;
        --color-gray-500: #B3BEC4;
        --color-gray-800: #1E2529;
        --color-gray-900: #1E2529;
        --color-gray-950: #111517;
        --primary-400: #6EE7A0;
        --primary-500: #15803D;
        --primary-600: #15803D;
        --color-primary-400: #6EE7A0;
        --success-400: #6EE7A0;
        --success-500: #6EE7A0;
        --color-success-400: #6EE7A0;
        --color-success-500: #6EE7A0;
        --warning-400: #FBBF24;
        --warning-500: #FBBF24;
        --color-warning-400: #FBBF24;
        --color-warning-500: #FBBF24;
    }

    .dark .fi-body {
        background-color: #111517;
        color: #F3F4F6;
    }

    .dark .fi-header-heading,
    .dark .fi-simple-header-heading,
    .dark .fi-logo {
        color: #F3F4F6;
    }

    .dark .fi-sidebar,
    .dark .fi-topbar {
        background-color: #171D20;
        color: #F3F4F6;
    }

    .dark .fi-sidebar .fi-logo,
    .dark .fi-sidebar .fi-sidebar-item-label,
    .dark .fi-topbar {
        color: #F3F4F6;
    }

    .dark .fi-sidebar .fi-sidebar-group-label,
    .dark .fi-sidebar .fi-sidebar-item-btn > .fi-icon,
    .dark .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn .fi-icon {
        color: #B3BEC4;
    }

    .dark .fi-sidebar .fi-sidebar-item-btn:hover,
    .dark .fi-sidebar .fi-sidebar-item-btn:focus-visible,
    .dark .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:hover,
    .dark .fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:focus-visible {
        background-color: #263035;
    }

    .dark .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn {
        background-color: #193D30;
    }

    .dark .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-sidebar-item-label,
    .dark .fi-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn > .fi-icon {
        color: #6EE7A0;
    }

    .dark .fi-breadcrumbs ol li,
    .dark .fi-breadcrumbs ol li .fi-icon,
    .dark .fi-ta-header-description,
    .dark .fi-input::placeholder,
    .dark .fi-select-input::placeholder,
    .dark textarea::placeholder,
    .dark input::placeholder {
        color: #B3BEC4;
        opacity: 1;
    }

    .dark .fi-breadcrumbs ol li a:hover {
        color: #F3F4F6;
    }

    .dark .fi-ta-ctn {
        background-color: #1E2529;
    }

    .dark .fi-ta-header,
    .dark .fi-ta-header-toolbar,
    .dark .fi-ta-table {
        border-color: #354147;
    }

    .dark .fi-ta-table > thead > tr > .fi-ta-header-cell,
    .dark .fi-ta-table > thead > tr > .fi-ta-selection-cell {
        background-color: #263035;
        color: #F3F4F6;
    }

    .dark .fi-ta-row,
    .dark .fi-ta-row > td {
        background-color: #1E2529;
        color: #F3F4F6;
    }

    .dark .fi-ta-row:hover,
    .dark .fi-ta-row:hover > td,
    .dark .fi-ta-row.fi-clickable:hover,
    .dark .fi-ta-row.fi-clickable:hover > td,
    .dark .fi-ta-row.fi-selected,
    .dark .fi-ta-row.fi-selected > td {
        background-color: #263035;
    }

    .dark .fi-sidebar .fi-sidebar-header-controls::after,
    .dark .fi-sidebar .fi-sidebar-footer,
    .dark .fi-topbar,
    .dark .fi-simple-main,
    .dark .fi-section,
    .dark .fi-modal-window,
    .dark .fi-ta-ctn,
    .dark .fi-dropdown-panel {
        border-color: #354147;
        --tw-ring-color: #354147;
    }

    .dark .fi-btn.fi-color-primary:not(.fi-outlined) {
        background-color: #15803D;
        color: #FFFFFF;
    }

    .dark .fi-btn.fi-color-primary:not(.fi-outlined):hover,
    .dark .fi-btn.fi-color-primary:not(.fi-outlined):focus-visible {
        background-color: #166534;
        color: #FFFFFF;
    }

    .dark .fi-link:not(.fi-color-warning):not(.fi-color-danger) {
        color: #6EE7A0;
    }

    .dark .fi-link.fi-color-warning {
        color: #FBBF24;
    }

    .dark .fi-link.fi-color-danger {
        color: #F87171;
    }

    .negocio-panel,
    .negocio-modal {
        border-radius: 0.75rem;
        background-color: #fff;
        box-shadow: 0 0 0 1px rgb(15 23 42 / 0.05);
    }

    .negocio-modal {
        border-radius: 1rem;
    }

    .dark .negocio-panel,
    .dark .negocio-modal {
        background-color: #1E2529;
        box-shadow: 0 0 0 1px #354147;
    }

    .negocio-hairline {
        border-color: #e5e7eb;
    }

    .dark .negocio-hairline {
        border-color: #354147;
    }

    .negocio-inset {
        border-radius: 0.75rem;
        border: 1px solid #e5e7eb;
        background-color: transparent;
    }

    .dark .negocio-inset {
        border-color: #354147;
        background-color: transparent;
    }

    .negocio-field,
    .mostrador-field {
        width: 100%;
        border-radius: 0.5rem;
        border: 1px solid #e5e7eb;
        background-color: #fff;
        padding: 0.625rem 0.75rem;
        font-size: 0.875rem;
        line-height: 1.25rem;
        color: #030712;
        outline: none;
    }

    .negocio-field:focus,
    .negocio-field:focus-within,
    .mostrador-field:focus,
    .mostrador-field:focus-within {
        border-color: #2E7D63;
        box-shadow: 0 0 0 1px #2E7D63;
    }

    .dark .negocio-field,
    .dark .mostrador-field {
        background-color: #171D20;
        border-color: #354147;
        color: #F3F4F6;
        color-scheme: dark;
        box-shadow: none;
    }

    .dark .negocio-field:focus,
    .dark .negocio-field:focus-within,
    .dark .mostrador-field:focus,
    .dark .mostrador-field:focus-within {
        border-color: #6EE7A0;
        box-shadow: 0 0 0 1px #6EE7A0;
    }

    .negocio-field::placeholder,
    .mostrador-field::placeholder,
    .negocio-field-control::placeholder {
        color: #9ca3af;
        opacity: 1;
    }

    .dark .negocio-field::placeholder,
    .dark .mostrador-field::placeholder,
    .dark .negocio-field-control::placeholder {
        color: #B3BEC4;
        opacity: 1;
    }

    .negocio-field-control {
        min-width: 0;
        flex: 1 1 auto;
        border: 0;
        background: transparent;
        padding: 0;
        font-size: 0.875rem;
        line-height: 1.25rem;
        color: inherit;
        outline: none;
    }

    .dark .negocio-field option,
    .dark .negocio-field optgroup,
    .dark .mostrador-field option,
    .dark .mostrador-field optgroup {
        background-color: #263035;
        color: #F3F4F6;
    }

    [x-cloak] {
        display: none !important;
    }

    .negocio-select-panel {
        position: absolute;
        inset-inline: 0;
        z-index: 50;
        margin-top: 0.25rem;
        width: 100%;
        overflow: hidden;
        border-radius: 0.5rem;
        background-color: #fff;
        color: #030712;
        box-shadow: 0 0 0 1px rgb(15 23 42 / 0.08), 0 10px 24px rgb(15 23 42 / 0.12);
    }

    .dark .negocio-select-panel,
    html.dark .negocio-select-panel {
        background-color: #1E2529;
        color: #F3F4F6;
        box-shadow: 0 0 0 1px #354147;
    }

    .negocio-select-search {
        border-bottom: 1px solid #e5e7eb;
        padding: 0.5rem 0.75rem;
    }

    .dark .negocio-select-search {
        border-bottom-color: #354147;
    }

    .negocio-select-options {
        max-height: 15rem;
        overflow-y: auto;
        padding: 0.25rem 0;
    }

    .negocio-select-option {
        display: flex;
        width: 100%;
        min-height: 2.5rem;
        align-items: center;
        padding: 0.625rem 0.75rem;
        text-align: left;
        font-size: 0.875rem;
        line-height: 1.25rem;
        color: inherit;
        background: transparent;
    }

    .negocio-select-option:hover,
    .negocio-select-option.is-active {
        background-color: rgb(15 23 42 / 0.05);
    }

    .dark .negocio-select-option:hover,
    .dark .negocio-select-option.is-active {
        background-color: #263035;
    }

    .negocio-select-empty {
        padding: 0.75rem;
        font-size: 0.875rem;
        color: #91A69A;
    }
</style>
<script>
    if (! window.negocioProtegeCamposNumericos) {
        window.negocioProtegeCamposNumericos = true;

        document.addEventListener('wheel', (event) => {
            const campo = event.target;

            if (campo instanceof HTMLInputElement && campo.type === 'number' && campo === document.activeElement) {
                campo.blur();
            }
        }, { passive: true });
    }
    window.negocioSelect = function (config) {
        return {
            open: false,
            query: '',
            value: '',
            label: '',
            options: [],
            searchablePref: config && config.searchable,
            get searchable() {
                if (this.searchablePref === true) {
                    return true;
                }

                if (this.searchablePref === false) {
                    return false;
                }

                return this.options.length > 5;
            },
            get filtered() {
                const query = this.query.trim().toLowerCase();

                if (query === '') {
                    return this.options;
                }

                return this.options.filter((option) => option.label.toLowerCase().includes(query));
            },
            init() {
                this.syncFromNative();

                const select = this.$refs.native;

                if (select) {
                    new MutationObserver(() => this.syncFromNative()).observe(select, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        attributeFilter: ['value', 'disabled'],
                    });
                }

                const close = () => {
                    this.open = false;
                };

                window.addEventListener('resize', close);
                window.addEventListener('scroll', close, true);
            },
            syncFromNative() {
                const select = this.$refs.native;

                if (! select) {
                    return;
                }

                this.options = Array.from(select.options).map((option) => ({
                    value: option.value,
                    label: option.textContent.trim(),
                }));
                this.value = select.value;
                this.label = this.options.find((option) => option.value === this.value)?.label ?? '';
            },
            toggle() {
                if (this.$refs.native?.disabled) {
                    return;
                }

                this.syncFromNative();
                this.open = ! this.open;
                this.query = '';

                if (this.open) {
                    this.$nextTick(() => {
                        this.positionPanel();
                        this.$refs.search?.focus();
                    });
                }
            },
            positionPanel() {
                const trigger = this.$refs.trigger;
                const panel = this.$refs.panel;

                if (! trigger || ! panel) {
                    return;
                }

                const rect = trigger.getBoundingClientRect();

                panel.style.position = 'fixed';
                panel.style.top = `${rect.bottom + 4}px`;
                panel.style.left = `${rect.left}px`;
                panel.style.width = `${Math.max(rect.width, 160)}px`;
                panel.style.marginTop = '0';
                panel.style.zIndex = '80';
            },
            choose(value) {
                const select = this.$refs.native;

                select.value = value;
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
                this.syncFromNative();
                this.open = false;
                this.query = '';
            },
        };
    };
</script>
