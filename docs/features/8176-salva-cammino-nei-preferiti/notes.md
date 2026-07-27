> Ticket: oc:8176

# Notes — Salva cammino nei preferiti (wm-package)

## Deviazioni dal piano

**Posizione del campo Nova spostata**: il campo Boolean `Show favorites` (`properties->show_favorites`) era stato inizialmente collocato nel tab "Home" (`home_tab()`, accanto a `Show searchbar`). Su richiesta esplicita del developer, spostato nel tab "Frontend" (`app_tab()`), subito dopo `Ugc Track Share Enabled` — stesso raggruppamento degli altri flag opt-in lato frontend (`show_travel_mode`, `ugc_track_share_enabled`). Nessun impatto su `AppConfigService`/test, solo riposizionamento del campo Nova.

## Bug trovati

Nessuno lato backend. Il bug del cuoricino invisibile (classi CSS icona errate) e il successivo redesign sola-lettura/interattivo sono interamente lato frontend — vedi `wm-core`.

## Decisioni

Vedi `overview.md` — decisione esplicita di non aggiungere scoping `app_id` sugli endpoint `layer/favorite/*` (Layer↔App, non Layer↔Utente), confermata dal developer in Fase: challenge.

## Review formale (`wm-skills:wm-review-ticket`) — finding corretto

**[blocker] `feature_image` serviva il file originale invece della thumbnail 400×200** — `LayerFavoriteController::list()` usava `$layer->getFirstMediaUrl('default')`, restituendo l'URL del media non processato. Il pattern consolidato altrove nel codebase per lo stesso tipo di risposta "leggera" (`AppConfigService.php`, `EcTrack.php:739`) usa invece `MediaService::make()->getThumbnailUrl($media)` (conversione Spatie 400×200). Corretto per coerenza — file potenzialmente molto più pesante altrimenti, senza garanzia di aspect ratio per il box che si aspetta un crop coerente.

## Follow-up

- Endpoint `add`/`remove` senza consumer reale oggi (solo `toggle` usato dal frontend) — vedi `wm-core/notes.md`.
- TOCTOU non atomico su `addFavorite`/`removeFavorite` (check-poi-toggle) — pattern replicato identico da `EcTrackController`, non introdotto da questo ciclo, non risolto (debito preesistente accettato).
