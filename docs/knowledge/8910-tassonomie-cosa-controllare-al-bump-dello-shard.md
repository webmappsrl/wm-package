# Tassonomie: cosa controllare su uno shard al bump del submodule

Il lavoro di oc:8910 porta nel package un modello unico per le classificazioni
(`taxonomy_terms` a due livelli, pivot `taxonomy_termables`, elenco per App in
`app_taxonomy_term`) e lo usa in due punti visibili: la sezione a tab nella scheda di una EC track
e la sezione «Filtri» nella scheda di un Layer. Qui sta cosa succede a uno shard che bumpa il
submodule — scritto da forestas, dove la feature è nata, e **senza aver aperto i repo di
camminiditalia e maphub**: le voci sotto «Da verificare sullo shard» sono domande, non difetti
accertati.

La procedura generale delle migration resta
[docs/howto/migration-wm-package.md](../howto/migration-wm-package.md).

## Cosa cambia per forza

**Tre migration obbligatorie.** Gli stub stanno nella root di `database/migrations/`, quindi sono
per tutti: `zz_2026_11_02_000001_create_taxonomy_terms_table`,
`zz_2026_11_02_000002_create_taxonomy_termables`,
`zz_2026_11_03_000003_create_app_taxonomy_term_table`. Vanno pubblicati con
`wm-package:publish-missing-migrations` e applicati **nello stesso rilascio del bump**: la Resource
Layer del package interroga `taxonomyTerms()` per costruire la sezione «Filtri», quindi senza la
tabella pivot la scheda di un layer va in errore. Non è un degrado silenzioso, è una pagina rotta.

**La scheda Layer cambia aspetto.** Al posto del pannello «Activities» compare la sezione
«Filtri», con le stesse tab della scheda EC track. Le attività restano lì dentro, non si perdono.
Le zone geografiche (`taxonomy_where`) **non** entrano nella sezione: su maphub sono circa 490
righe e un campo a scelta multipla con quei numeri non è usabile, quindi restano nel loro pannello.

**Semantica dei filtri di un layer.** OR fra i termini della stessa tassonomia, AND fra tassonomie
diverse: «(sentiero o cammino) e (difficoltà BC)». È la stessa regola che già valeva fra attività e
zone, e in `LayerService` è una sottoquery per tassonomia, non un `whereIn` unico
(`src/Services/Models/LayerService.php`).

**Niente `composer update` e niente build.** Non è stata aggiunta nessuna voce PSR-4 nuova — il
`ChoiceFieldServiceProvider` sta fuori dalla cartella del campo apposta — e i `dist` dei campi Nova
sono versionati.

**Reindex più ampio.** `SyncAutoLayerAfterTrackTaxonomyChangeJob` reindicizza sia le tracce che
entrano nel layer sia quelle che ne escono, perché `properties.layers` delle uscite va corretto. Su
un layer con poche centinaia di tracce è immediato; su layer molto grandi il costo di un cambio di
filtro cresce di conseguenza. Dopo il bump, Horizon va riavviato.

## Da verificare sullo shard

**Collisione di nomi.** Il package aggiunge `App::taxonomies()` (`src/Models/App.php:97`),
`Layer::taxonomyTerms()` (`src/Models/Layer.php:146`) e lo scope `TaxonomyTerm::taxonomies()`.
`taxonomies()` su `App` è un nome generico: se lo shard ha già un metodo o una relazione con quel
nome sul proprio `App`, uno dei due vince in silenzio. Da cercare prima del bump.

**Resource `Layer` propria.** Se lo shard estende la Resource del package e ridefinisce `fields()`,
può perdere la sezione «Filtri» o — peggio — tenere il vecchio pannello «Activities» accanto alla
nuova sezione. Due controlli sulla stessa relazione nella stessa pagina sono il bug che su forestas
ha svuotato un layer al primo salvataggio: il campo che non mostra tutte le righe le stacca.

**Modello EC track.** La query dei filtri confronta il morph type dei pivot e accetta
`config('wm-package.ec_track_model')`, `Wm\WmPackage\Models\EcTrack` e `App\Models\EcTrack`. Se lo
shard usa una classe propria, `EC_TRACK_MODEL` nel suo `.env` deve essere valorizzato, altrimenti i
pivot salvati con quel morph type non vengono trovati.

**Trait `TaxonomyAbleModel` sul modello EC track dello shard.** Senza, la relazione dei termini non
esiste e i campi delle classificazioni non si risolvono.

## Perché così

- **Pivot con unicità e due soli livelli** (`unique(taxonomy_term_id, termable_type, termable_id)`,
  trigger `taxonomy_terms_two_levels`): un termine con un nonno non ha significato nel modello, e
  farlo rifiutare al database costa meno che presidiarlo nel codice.
- **Le attività storiche (`taxonomy_activities`) non sono state convertite.** La scelta è stata
  dividere in due tempi: prima un'interfaccia unica — l'utente inserisce tutte le classificazioni
  allo stesso modo, con l'icona — poi, quando serve, la convergenza dei modelli. Per questo
  `config/wm-package.php` ha `legacy_taxonomies`, che dichiara per ogni tassonomia storica relazione,
  modello, tab e se è a scelta singola.
- **Le tassonomie di sistema si accendono da sole alla creazione di un'App** (`AppObserver`), le
  custom no: un'app nuova deve essere utilizzabile senza configurazione, e l'elenco per App serve a
  togliere, non ad aggiungere.
