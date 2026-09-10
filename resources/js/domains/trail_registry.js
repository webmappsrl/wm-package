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
