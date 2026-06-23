> Ticket: oc:7648

# Analytics Layer — Selezione range temporale

## Cosa cambia
La sezione Analytics del layer passa da un range fisso di 30 giorni a un selettore
dinamico che permette di scegliere tra finestre mobili (30, 90, 365 giorni) e singoli
mesi di calendario (da `created_at` del layer fino al mese corrente).

## Perché
Il cliente ha bisogno di vedere l'andamento storico del proprio cammino oltre la
finestra degli ultimi 30 giorni, e di confrontare mesi specifici (es. "come è andata
a marzo 2026?"). Il tracking è attivo da gennaio 2026 per tutti i layer.

## Requisiti
- [ ] Dropdown range nel componente Vue con opzioni: Ultimi 30 giorni (default),
      Ultimi 90 giorni, Ultimi 365 giorni, + un mese per ogni mese da `created_at`
      del layer fino al mese corrente (es. Gen 2026, Feb 2026, …)
- [ ] Ogni cambio range triggera una nuova fetch verso l'endpoint con query string
      `?days=30|90|365` oppure `?month=2026-03`
- [ ] Titolo della card aggiornato dinamicamente in base al range selezionato
- [ ] `AnalyticsService` accetta il range come parametro e costruisce la clausola
      WHERE corretta per giorni o per mese di calendario
- [ ] Cache key include il range: `posthog:{event}:{id}:usage:{range}`
- [ ] TTL cache differenziato: 900s (30gg), 3600s (90gg), 21600s (365gg e mesi)
- [ ] `LayerAnalyticsCard.php` passa `created_at` del layer come prop `tracking_since`
      al componente Vue
- [ ] `AnalyticsController` legge `?days` o `?month` dalla request e li passa al service

## Rischi
- Query PostHog su 365 giorni possono essere lente o costose — mitigato dal TTL lungo
  e da `Cache::lock` per i range 90gg e 365gg (evita stampede a cache fredda)
- Parametri `?days` e `?month` mutuamente esclusivi: il controller dà precedenza a
  `?month` se presente, altrimenti legge `?days`
- Validazione strict nel controller: `?month` deve matchare `/^\d{4}-\d{2}$/`,
  `?days` deve essere in whitelist `[30, 90, 365]` — fallback a 30gg se invalido
- Rollback parziale (PHP rollbackato, Vue aggiornato): `tracking_since` gestito con
  fallback a `'2026-01-01'` nel Vue se la prop è assente o non valida
- Mesi molto vecchi con pochi dati potrebbero confondere l'utente (grafico quasi vuoto)
  — accettato, nessuna logica di filtraggio "mesi con dati"

## Out of scope
- Granularità mensile nel grafico (bar per mese invece che per giorno)
- Tracciamento click "navigazione" (ticket separato)
- Differenziazione freemium (30gg gratis, range più ampi a pagamento)
- Automazione creazione insight Posthog

## Moduli toccati
**wm-package:**
- `src/Services/PostHog/AnalyticsService.php` — accetta range, query SQL dinamica, TTL differenziato, nuova query track downloads
- `src/Http/Controllers/Nova/AnalyticsController.php` — legge query string, include track_downloads nella risposta
- `src/Nova/Cards/LayerAnalytics/src/LayerAnalyticsCard.php` — passa `tracking_since`
- `src/Nova/Cards/LayerAnalytics/resources/js/components/LayerAnalyticsCard.vue` — dropdown, fetch dinamica, tabella download per traccia
- `src/Nova/Cards/LayerAnalytics/dist/js/card.js` — rebuild necessario
