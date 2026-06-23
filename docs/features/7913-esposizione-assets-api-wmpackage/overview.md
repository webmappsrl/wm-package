> Ticket: oc:7913

# Esposizione assets API wm-package

## Cosa cambia

`AppController::getOrDownloadIcon` smette di usare `isset($app->$type)` come guardia e usa invece `$app->getMedia($type)->first()`. Se il media non esiste restituisce 404; se esiste, serve il file dallo storage (S3 o locale).

## Perché

Quando le immagini di un'app vengono caricate tramite Spatie Media Library (via `Images::make`), le colonne `apps.icon` / `apps.splash` restano `NULL`. La guardia `isset($app->$type)` controlla la colonna, non la media library: l'endpoint risponde 404 anche quando il file esiste su S3. Il problema non è specifico di Maphub — riguarda qualsiasi app su qualsiasi shard dove le colonne non sono valorizzate ma il media è in Spatie.

## Requisiti

- [x] `getOrDownloadIcon` usa `$app->getMedia($type)->first()` come unica fonte di verità
- [x] Se il media non esiste, risponde `404 Not Found`
- [x] Se il media esiste, serve il file dallo storage (comportamento invariato rispetto al codice attuale dopo la guardia)
- [x] Il fix si applica a tutti i tipi gestiti dal metodo: `icon`, `icon_small`, `splash`, `feature_image`, `icon_notify`, `logo_homepage`
- [x] Nessun fallback sulla colonna DB — app non migrate ricevono 404 (comportamento atteso e diagnosticabile)

## Rischi

- **Regressione su altri shard:** non presente. Le app che oggi rispondono 200 (es. osm2cai2 app 1) hanno già colonna e media allineati — `getMedia()->first()` trova il record e il comportamento è invariato.
- **Content-Type null:** il metodo usa `getCustomProperty('mime-type')` che può essere null. Tracciato in oc:8122 come fix separato, non bloccante per questo ticket.
- **Nessun test automatico:** la verifica è manuale (curl sull'endpoint).
- **Verifica locale limitata:** il bucket MinIO locale (`wmfe/maphub`) contiene solo le cartelle `1` e `json` — App 2 non ha file fisici sincronizzati localmente. La verifica completa del 200 richiede staging/produzione oppure un upload dell'icona via Nova in locale.

## Out of scope

- Fix del `Content-Type` null (`getCustomProperty('mime-type')` → `mime_type`) — tracciato in oc:8122
- Aggiunta di `feature_image`, `icon_notify`, `logo_homepage` a `registerMediaCollections()`
- Test automatici per `AppController`

## Moduli toccati

| File | Repo | Tipo modifica |
|------|------|---------------|
| `src/Http/Controllers/Api/AppController.php` | `wm-package` | Fix guardia in `getOrDownloadIcon` |
