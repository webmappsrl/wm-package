/**
 * La card che spiega, in cima all'elenco, cosa sono le anomalie del catasto.
 *
 * E' registrata con una render function invece che con un template: il Vue
 * che Nova carica e' la build runtime-only, che un template scritto come
 * stringa non saprebbe compilare. Cosi' non serve alcun bundle da compilare
 * per questa card — `Vue` e' la globale su cui le card gia' compilate del
 * package fanno externals, quindi e' garantita presente.
 *
 * Lo script viene caricato solo quando il dominio e' acceso (vedi
 * WmPackageServiceProvider::registerEnabledDomains()).
 */
Nova.booting((app) => {
    app.component('trail-registry-notice-card', {
        props: {
            card: { type: Object, required: true },
        },

        render() {
            return Vue.h('div', {
                // Stesse classi della Card nativa di Nova, cosi' la spiegazione
                // non sembra un corpo estraneo sopra la tabella.
                class: 'bg-white dark:bg-gray-800 shadow rounded-lg px-6 py-4 leading-normal',
                // Il contenuto lo compone la Resource, non arriva da un utente.
                innerHTML: this.card.body,
            })
        },
    })
})

/**
 * Le due card della percorribilita' sulla dashboard (oc:8989).
 *
 * Stessa scelta della card qui sopra: render function invece di template,
 * perche' il Vue che Nova carica e' la build runtime-only e non compila
 * template scritti come stringa. Niente bundle da compilare.
 */
Nova.booting((app) => {
    const box = (children) =>
        Vue.h('div', { class: 'bg-white dark:bg-gray-800 shadow rounded-lg px-6 py-4 leading-normal h-full' }, children)

    const title = (text) =>
        Vue.h('h3', { class: 'text-sm font-bold uppercase tracking-wide text-gray-500 mb-3' }, text)

    // Una card sola per tutte le distribuzioni: titolo e nota arrivano dal PHP,
    // così la percorribilità e la validazione non hanno due componenti gemelli
    // che divergono alla prima modifica fatta su uno solo.
    app.component('trail-registry-taxonomy-distribution-card', {
        props: { card: { type: Object, required: true } },

        render() {
            const slices = this.card.slices || []
            const total = slices.reduce((sum, slice) => sum + slice.count, 0)

            const rows = slices.map((slice) =>
                Vue.h('div', { class: 'flex justify-between py-1 border-b border-gray-100 dark:border-gray-700' }, [
                    Vue.h('span', slice.label),
                    Vue.h('span', { class: 'font-bold tabular-nums' }, String(slice.count)),
                ])
            )

            // Il termine tenuto fuori si legge qui sotto, con la frase che il PHP
            // ha già composto: dentro l'elenco schiaccerebbe tutto il resto.
            const note = this.card.excludedNote
                ? Vue.h(
                      'p',
                      { class: 'text-xs text-gray-500 mt-3' },
                      this.card.excludedNote
                          .replace(':count', String(this.card.excludedCount))
                          .replace(':total', String(total))
                  )
                : null

            return box([title(this.card.title), ...rows, note])
        },
    })

    app.component('trail-registry-inconsistencies-card', {
        props: { card: { type: Object, required: true } },

        render() {
            const items = this.card.items || []

            if (items.length === 0) {
                return box([
                    title('Incongruenze catastali'),
                    Vue.h('p', { class: 'text-sm text-gray-500' }, 'Nessun tipo dichiarato.'),
                ])
            }

            // Ogni riga porta all'elenco delle anomalie filtrato su quel tipo: un
            // numero da solo dice che c'è del lavoro, non quale. Le righe a zero
            // restano testo, perché un link a una lista vuota è un vicolo cieco.
            const rows = items.map((item) => {
                const head = Vue.h('div', { class: 'flex justify-between' }, [
                    Vue.h('span', item.label),
                    Vue.h('span', { class: 'font-bold tabular-nums' }, String(item.count)),
                ])

                return Vue.h('div', { class: 'py-2 border-b border-gray-100 dark:border-gray-700' }, [
                    item.count > 0 && item.url
                        ? Vue.h(
                              'a',
                              { href: item.url, class: 'block no-underline text-primary-500 hover:underline' },
                              [head]
                          )
                        : head,
                    item.description
                        ? Vue.h('p', { class: 'text-xs text-gray-500 mt-1' }, item.description)
                        : null,
                ])
            })

            return box([title('Incongruenze catastali'), ...rows])
        },
    })
})
