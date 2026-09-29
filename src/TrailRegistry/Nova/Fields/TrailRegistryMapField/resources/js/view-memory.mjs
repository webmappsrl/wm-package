/**
 * La vista della mappa (centro e zoom) salvata allo smontaggio e ripresa al
 * montaggio successivo della stessa scheda (oc:8662).
 *
 * Serve perche' Nova, dopo un'Action, non aggiorna il dettaglio: lo ricrea.
 * `getResource()` in `views/Detail.vue` azzera `panels`, i pannelli si
 * smontano e la mappa rinasce da zero, inquadrando di nuovo il tracciato.
 * Il gestore che aveva ingrandito una zona per confrontare i numeri se la
 * ritroverebbe spostata.
 *
 * La memoria vale pochi secondi e una volta sola: un rimontaggio subito dopo
 * lo smontaggio e' una ricarica; una riapertura della scheda piu' tardi e'
 * una visita nuova, e deve inquadrare il tracciato come sempre.
 */
export function createViewMemory(maxAgeMs) {
    const saved = new Map();

    return {
        remember(key, view, now) {
            if (!view || !Array.isArray(view.center) || typeof view.zoom !== 'number') {
                return;
            }

            saved.set(key, { view, at: now });
        },

        recall(key, now) {
            const entry = saved.get(key);
            saved.delete(key);

            if (!entry || now - entry.at > maxAgeMs) {
                return null;
            }

            return entry.view;
        },
    };
}
