/**
 * Componente Vue per la seconda modale del redesign a due modali di
 * ImportTaxonomyWhere (sorgente geohub) — vedi overview.md, sezione
 * "Follow-up 2: due modali separati per la selezione". Registrato con una
 * render function (non un file .vue compilato): nessun bundle da costruire
 * per un componente cosi' semplice — stesso pattern gia' usato per
 * trail-registry-notice-card (resources/js/domains/trail_registry.js).
 *
 * Il nome del componente DEVE combaciare esattamente con
 * ImportTaxonomyWhere::GEOHUB_WHERE_SELECTION_MODAL_COMPONENT (PHP).
 *
 * Layout/backdrop in `style` inline, non classi Tailwind arbitrarie: oc:7546
 * ha gia' verificato che classi non usate altrove da Nova (es. bg-opacity-50,
 * px-5, gap-3) vengono eliminate dal purge del CSS compilato e non hanno
 * alcun effetto. Le uniche classi Tailwind qui sotto (nei bottoni) sono
 * copiate 1:1 da src/Nova/Fields/_shared/resources/js/components/Button.vue,
 * gia' verificate presenti nel CSS di Nova.
 */
Nova.booting((app) => {
    const h = Vue.h

    const BUTTON_BASE = 'border text-left appearance-none cursor-pointer rounded text-sm font-bold inline-flex items-center justify-center h-9 px-3'
    const BUTTON_VARIANTS = {
        primary: 'shadow bg-primary-500 border-primary-500 text-white hover:bg-primary-400 hover:border-primary-400',
        secondary: 'bg-transparent border-transparent text-gray-600 hover:bg-gray-100',
    }

    const button = (label, variant, onClick, disabled) => h('button', {
        type: 'button',
        class: `${BUTTON_BASE} ${BUTTON_VARIANTS[variant] || BUTTON_VARIANTS.primary}`,
        style: disabled ? 'opacity:0.5;pointer-events:none' : '',
        disabled: !!disabled,
        onClick,
    }, label)

    app.component('geohub-where-selection-modal', {
        props: {
            data: { type: Object, required: true },
        },

        emits: ['confirm', 'close'],

        data() {
            return {
                rows: (this.data.rows || []).map(row => ({ ...row })),
                loading: false,
                result: null,
                error: null,
            }
        },

        computed: {
            // Label già tradotte lato server (handleGeohub(), via __()) — il
            // componente non ha accesso al traduttore Laravel. Il fallback
            // italiano copre solo l'improbabile caso di un payload costruito
            // altrove senza passare da handleGeohub().
            labels() {
                return {
                    title: 'Territori da importare',
                    select_all: 'Seleziona tutte',
                    deselect_all: 'Deseleziona tutte',
                    cancel: 'Annulla',
                    import: 'Importa',
                    close: 'Chiudi',
                    importing: 'Import in corso...',
                    unexpected_error: "Errore imprevisto durante l'import.",
                    ...(this.data.labels || {}),
                }
            },
            allSelected() {
                return this.rows.length > 0 && this.rows.every(row => row.checked)
            },
            selectedCount() {
                return this.rows.filter(row => row.checked).length
            },
        },

        methods: {
            toggleAll() {
                const target = !this.allSelected
                this.rows = this.rows.map(row => ({ ...row, checked: target }))
            },

            toggleRow(id) {
                this.rows = this.rows.map(row => (row.id === id ? { ...row, checked: !row.checked } : row))
            },

            async submit() {
                if (this.loading || this.selectedCount === 0) {
                    return
                }

                this.loading = true
                this.error = null

                try {
                    const response = await Nova.request().post('/nova-vendor/geohub-where-selection/import', {
                        app_id: this.data.app_id,
                        selected_ids: this.rows.filter(row => row.checked).map(row => row.id),
                    })
                    this.result = response.data
                } catch (e) {
                    this.error = (e.response && e.response.data && e.response.data.message) || this.labels.unexpected_error
                } finally {
                    this.loading = false
                }
            },

            close() {
                this.$emit('close')
            },
        },

        render() {
            const overlay = h('div', {
                style: 'position:absolute;inset:0;background-color:rgba(0,0,0,0.5)',
                onClick: this.close,
            })

            let body
            if (this.loading) {
                body = h('div', { style: 'padding:32px 0;text-align:center' }, this.labels.importing)
            } else if (this.result) {
                body = h('div', { style: 'padding:16px 0' }, this.result.message)
            } else if (this.error) {
                body = h('div', { style: 'padding:16px 0;color:#ef4444' }, this.error)
            } else {
                body = h(
                    'div',
                    { style: 'max-height:360px;overflow-y:auto;padding:8px 0' },
                    this.rows.map(row => h('label', {
                        key: row.id,
                        style: 'display:flex;align-items:center;gap:8px;padding:6px 0;cursor:pointer',
                    }, [
                        h('input', {
                            type: 'checkbox',
                            checked: row.checked,
                            onChange: () => this.toggleRow(row.id),
                        }),
                        h('span', {}, row.label),
                    ]))
                )
            }

            const footer = (this.result || this.error)
                ? h('div', { style: 'display:flex;justify-content:flex-end;margin-top:16px' }, [
                    button(this.labels.close, 'primary', this.close),
                ])
                : h('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:16px' }, [
                    // Disabilitati anche durante this.loading: senza questo,
                    // "Annulla" chiuderebbe la modale (emit close) mentre la
                    // POST è ancora in volo, e il toggle selezione resterebbe
                    // interagibile senza alcun effetto reale.
                    button(this.allSelected ? this.labels.deselect_all : this.labels.select_all, 'secondary', this.toggleAll, this.loading),
                    h('div', { style: 'display:flex;gap:8px' }, [
                        button(this.labels.cancel, 'secondary', this.close, this.loading),
                        button(`${this.labels.import} (${this.selectedCount})`, 'primary', this.submit, this.loading || this.selectedCount === 0),
                    ]),
                ])

            const panel = h('div', {
                style: 'position:relative;background:white;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.35);width:100%;max-width:32rem;padding:24px;max-height:90vh;overflow-y:auto',
                class: 'dark:bg-gray-800 dark:text-white',
            }, [
                h('h3', { style: 'font-size:1.25rem;font-weight:400;margin-bottom:8px' }, this.labels.title),
                body,
                footer,
            ])

            const wrapper = h('div', {
                style: 'position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;padding:16px',
            }, [overlay, panel])

            // Teleport verso <body>, stesso motivo gia' documentato per
            // oc:7546 (containing block alterato da transform in catena nei
            // pannelli Nova). Verificato dal vivo (Task 3, Step 3): `Vue.Teleport`
            // e' disponibile come globale e la modale si posiziona correttamente.
            return h(Vue.Teleport, { to: 'body' }, [wrapper])
        },
    })
})
