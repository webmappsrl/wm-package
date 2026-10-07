> Ticket: oc:8672

# Notes — Chiavi di traduzione in inglese nel Catasto Sentieri (parte wm-package)

## Divergenze dal piano, task per task

### Task 4 test esistenti

L'elenco dell'overview era incompleto, come previsto. La ricerca con il tokenizer su `tests/`,
estesa alle stringhe letterali fuori da `__()`, ha trovato da aggiornare anche:

- `TrailRegistryAnomaliesTest`: il tooltip composto `'Settore ZNUB3 — dichiarato dal codice'`
  diventa `'Sector ZNUB3 — declared by the code'`;
- `TrailRegistryCodeMapTest`: oltre alle etichette, il frammento `'Sentiero ZNUB562'` usato per
  trovare la feature del sentiero;
- `MapLegendRendererTest`: i frammenti nei `toContain` (`'Traccia dell'`, `'Numero liberato'`,
  `'Profilo altimetrico'`, …).

Non sono stati toccati `MenuSectionInjectionTest` (usa `'Catasto'` come nome qualunque di sezione,
per provare `injectMenuSectionItems()` in astratto), `ApproveTrailApplicationTest`,
`TrailRegistryMapFieldTest` e i nomi di prova come `'Sentiero di prova'`: sono dati, non etichette.

In `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php:242` il confronto con il
testo letterale `'già importata'` è diventato `__('already imported')`, così regge con qualunque
lingua: quel test usa `Tests\TestCase` e gira dalla suite di uno shard.

### Task 5 verifica

- **Non è stato possibile lanciare tutta la suite del package.** `vendor/bin/pest --filter=…` e
  `vendor/bin/pest tests/` si fermano al caricamento su
  `tests/Feature/AddSurnameToUsersMigrationTest.php`, che usa `Tests\TestCase`, inesistente nel
  package. È preesistente. I test si lanciano indicando il percorso. Esito:
  - `tests/Feature/TrailRegistry/`: 303 test, tutti verdi;
  - `tests/Feature/UgcControllerTaxonomyWhereAsyncFallbackTest.php` e
    `tests/Unit/EcDescriptionTiptapTest.php`, gli unici altri con il `TestCase` del package che
    citano chiavi toccate: verdi.
- **I test del package con `Tests\TestCase`** che citano chiavi toccate
  (`ImportTaxonomyWhereGeohubSourceTest`, `BulkEditActionTest`, `TaxonomyWherePolicyTest`) vanno
  lanciati da forestas, che oggi non ha il database `forestas_testing`: vedi le note di forestas.
- **PHPStan:** `vendor/bin/phpstan analyse` riporta 1017 errori su tutto il package, preesistenti.
  Sui file toccati cadono su righe non modificate (per esempio `EcPoi.php:50`,
  `WmPackageServiceProvider.php:201`); il lavoro cambia solo argomenti stringa di `__()` e le
  etichette di `MapLegendRenderer`.
- **Pint** sui file toccati: nessuna correzione.

### Task 6 documentazione

Emerso nella review: anche `docs/howto/attivare-catasto-sentieri.md` andava aggiornato. Indicava
agli shard `MenuSection::make(__('Catasto'), …)`, mentre il package ora cerca la sezione con
`__('Trail registry')`: ora indica la chiave nuova e spiega perché deve essere esattamente quella.

## Bug trovati

- `tests/Feature/AddSurnameToUsersMigrationTest.php` impedisce di lanciare la suite del package
  senza indicare i percorsi (preesistente, fuori scope).

## Decisioni

- La conversione è fatta con il tokenizer, non a mano né con `sed`: cambia solo il token stringa
  argomento di `__()` (108 sostituzioni, 9 delle quali nelle costanti di `MapLegendRenderer`).
- I JSON sono stati riscritti con `json_encode(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES)`, verificando prima che sul file non modificato il risultato fosse
  identico byte per byte. Le voci nuove sono in fondo.
- Il test nuovo è stato visto fallire prima della conversione (3 test rossi su 4) e passare dopo.
- Il test nuovo stampa su `STDERR` le chiavi già inglesi senza voce nei percorsi dello scope
  (`EC Poi`, `Info`, `Description`, … in `EcPoi.php`; `DEM`; `Area`) e le due chiamate dinamiche di
  `MapLegendRenderer`.

## Follow-up

- Il controllo a mano in Nova (Task 5.4) è da fare.
- Allargare il test delle chiavi a tutto `src/` e tradurre le chiavi già inglesi senza voce è un
  lavoro a parte.
