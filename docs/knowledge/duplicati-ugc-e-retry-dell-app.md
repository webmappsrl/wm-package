# Duplicati UGC e retry dell'app

Perché lo store UGC aggiorna invece di creare, e come si sistemano i duplicati già presenti.

## Come funziona oggi

**Il retry dell'app.** L'app (wm-core, `UgcService`) tiene ogni UGC in una coda sul device finché
la store non risponde con successo. Su qualunque errore (timeout, rete, upload delle foto fallito a
metà) la rimanda alla sync successiva, dopo 60 secondi o alla riapertura dell'app, con lo **stesso
`properties.uuid`** e **tutte le foto**, con gli stessi byte. Se il server aveva già salvato, senza
protezione nasce un duplicato.

**Lo store** (`UgcController::store()`, tracce e POI, `api/ugc/*/store` e `api/v2/ugc/*/store`):
- cerca, **tra gli UGC dell'utente autenticato**, un record con lo stesso `properties.uuid`, letto dai **dati validati**: l'app manda un
  multipart con la feature in JSON nel campo `feature`, che `$request->input()` non vede;
- se lo trova (il più vecchio, se ce ne sono già più d'uno) lo **aggiorna** e risponde 201 con il
  suo id; le properties ricevute vanno **sopra** quelle salvate, quindi le chiavi che l'app non
  manda (per esempio `layer_id` assegnato dal server) restano;
- le foto si salvano solo se il loro sha256 è diverso da quello delle foto già associate (store ed
  edit); l'hash sta in `custom_properties.sha256` del media, e per i media vecchi si calcola al
  volo. Un file illeggibile conta come "diverso" e si logga sul canale `ugc`.

**I duplicati già presenti** si sistemano con `wm:fix-duplicated-ugc` (tracce e POI, `--type=tracks|pois` per limitarlo a uno dei due): report
a schermo di default, `--execute` per applicare. Per ogni uuid tiene la riga più vecchia, unisce le
properties delle copie in ordine di data (`properties.updatedAt` del device, poi `updated_at`, poi
`id`; `id`, `created_at`, `updated_at`, `taxonomy_where`, `taxonomyWheres` restano del padre),
sposta le foto scartando i doppioni per sha256, archivia le copie in `ugc_duplicates_archive` e le
cancella. Un gruppo con geometrie distanti più di 1 metro non viene toccato ("da verificare").
Ogni gruppo e ogni lancio vanno nel canale di log `duplicated-ugc`.

**Vincoli:**
- il command **non sposta i riferimenti esterni** verso `ugc_tracks` (FK di altre tabelle): una
  FK con `ON DELETE CASCADE` cancella le righe delle copie, una con `SET NULL` le stacca. Prima di
  `--execute` su un progetto con tabelle che puntano a `ugc_tracks`, il command va esteso;
- il command non riconosce le geometrie nulle (le tratta come distanza 0);
- l'app non rimuove le UGC sincronizzate che il server non restituisce più: chi aveva già
  scaricato due copie continua a vedere sul telefono quella cancellata, fino al logout o a una
  modifica (il server risponde 404 e l'app la toglie);
- due store identiche nello stesso istante creano ancora due righe (nessun indice unico
  sull'uuid); dentro una stessa app le sync sono serializzate.

**Test sui casi reali** (dump della produzione di camminiditalia, 07/10/2026), da conservare:
`tests/Feature/UgcStoreRetryRealCaseTest.php` e `tests/Feature/WmFixDuplicatedUgcTracksRealCaseTest.php`.

## Perché così

- **Aggiornare invece di rifiutare** (oc:8718): l'app tratta ogni errore come "riprova" e
  rimanderebbe la stessa UGC ogni 60 secondi, per sempre; rispondere OK senza toccare nulla
  perderebbe le foto proprio nei casi in cui il primo invio si è interrotto a metà.
- **Ricerca limitata all'utente** (oc:8718, review): l'uuid è pubblico nel link di condivisione
  `/share/ugc-track/{uuid}` (oc:8183); senza filtro chiunque riceva un link potrebbe sovrascrivere
  la traccia altrui con una store. Un retry legittimo arriva sempre dallo stesso utente.
- **Unire le properties invece di sostituirle** (oc:8718): sulle tracce di camminiditalia il
  `layer_id` lo scrive sempre il server (nessuna traccia ha `form.layer_id`) e l'app non lo
  rimanda; sostituendo, ogni retry cancellerebbe il cammino, anche quello corretto a mano
  (traccia 321, oc:8466).
- **Foto confrontate per contenuto** (oc:8718): l'app rimanda gli stessi byte con nomi
  posizionali (`image_0.jpg`, …), quindi il nome non serve. Sui dati reali 14 foto su 39 delle
  copie erano identiche a quelle del padre.
- **Copie archiviate e cancellate, non marcate** (oc:8718): restano interrogabili in
  `ugc_duplicates_archive` (con `reference_id` senza FK, perché l'archivio deve sopravvivere al
  padre) senza righe morte in Nova e nelle statistiche.
- **Unione in ordine di data** (oc:8718): ogni invio dell'app è uno stato voluto dall'utente; sul
  caso reale `1529846e` la modifica `difficulty: easy → medium` stava sulla copia.

## Come ci siamo arrivati

- **Fix dei POI di oc:6951** (superato da oc:8718): cercava l'uuid in
  `UgcPoiController::getModelIstance()` con `$request->input('properties.uuid')`, che con il
  multipart dell'app restituisce `null`. Il fix non è mai scattato per le richieste dell'app
  (verificato con due richieste HTTP reali il 07/10/2026). Per le tracce non era mai stato esteso.
- **Copie marcate e assegnate a un utente tecnico** (il command dei POI di osm2cai2): scartato per
  le tracce, perché lascia righe morte e un utente finto; sostituito dall'archivio.
- **Report in CSV** (previsto dal ticket, sul modello di osm2cai2): tolto su richiesta del dev; il
  report è a schermo e nel log `duplicated-ugc`.
