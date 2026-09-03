> Ticket: oc:8469

# Notes — Fix identifier TaxonomyWhere

## Deviazioni dal piano

- **Regola per i record creati a mano cambiata durante l'esecuzione.** Il piano
  prevedeva `{source}-{slug(name)}-{id}` assegnato in un hook `created()` con
  `saveQuietly()` (l'id non esiste in `creating()`). Su indicazione del dev si è
  passati a un **contatore progressivo applicato solo in caso di omonimia**:
  `forestas-area-nuova`, poi `-2`, `-3`. Migliore in due modi: l'identifier resta
  pulito quando non c'è conflitto, e tutto si risolve in `creating()`, quindi
  sono spariti l'hook `created()`, il `saveQuietly()` (secondo salvataggio per
  ogni record) e il flag transiente `$sourceStamped`.

- **Ampliamento di scope: nomi da OSMFeatures.** Durante il Task 5 il dev ha
  notato che il record 11 aveva nome `올리아스트라` (Ogliastra in coreano). È un
  bug distinto dall'identifier, in `OsmfeaturesClient::getAdminAreasIds()`, ma il
  dev ha scelto di includerlo in questo ticket invece di aprirne uno separato.
  Vedi "Bug trovati".

## Bug trovati

- **`OsmfeaturesClient::getAdminAreasIds()` ripiegava sulla prima lingua
  disponibile.** L'endpoint `admin-areas/list` con `tags=name` restituisce solo
  le traduzioni `name:<lang>`, mai il tag `name` base. Il codice faceva
  `$nameObj['it'] ?? $nameObj['en'] ?? reset($nameObj)`: per un'area con la sola
  traduzione coreana (`R19621461`, chiavi disponibili: `['ko']`) salvava il
  coreano — e per giunta **sotto la chiave `it`**, perché Spatie usa il locale
  corrente. Dove non esisteva alcuna traduzione salvava l'id (`R19622159`).
  Verificato sull'API reale, non dedotto.
  Fix: niente più `reset()`; senza `it`/`en` il nome resta `null`, l'action salva
  l'id come segnaposto e `FetchTaxonomyWhereGeometryJob` lo sostituisce con
  `properties.name` del dettaglio — che il job **scaricava già** per la
  geometria, quindi zero chiamate HTTP aggiuntive.

- **Confronto loose in `TaxonomyObserver`.** `if ($taxonomy->identifier != null)`
  è `false` con stringa vuota (verificato: `'' != null` → `false`), quindi un
  identifier vuoto **saltava il check di unicità** e finiva in una colonna
  unique, producendo un 23505 grezzo al secondo record. Corretto con confronto
  strict e normalizzazione dello slug vuoto a `null`.

- **Metodi cancellati per errore durante l'implementazione.** Una sostituzione a
  blocchi su `TaxonomyWhere.php` ha rimosso `getOsmfeaturesId()`,
  `getAdminLevel()` e `getSource()`, causando un errore in Nova
  (`Call to undefined method ...::getSource()`). Ripristinati e verificati con un
  diff dei metodi rispetto a `HEAD`. Era esattamente il rischio annotato nel
  "punto di attenzione" del piano.

## Decisioni

- **Identifier derivato dall'id di sorgente, non dal nome.** La challenge ha
  mostrato che la regola iniziale (`{source}-{admin_level}-{slug(name)}`) creava
  una classe di collisioni (quartieri omonimi a L10) che poi andava mitigata con
  uno skip — cioè con perdita di dato permanente. Derivando da `source` +
  `osmfeatures_id`/`osm2cai_id` la collisione è impossibile per costruzione.
  Confermato empiricamente: **427 record importati, 427 identifier distinti,
  zero collisioni** su L4, L6, L8 (377 comuni), L9 e L10.

- **Guard `if (empty($identifier))` dell'observer lasciato invariato.** La
  challenge ha evidenziato che rimuoverlo avrebbe fatto ricalcolare l'identifier
  di `TaxonomyActivity` e `TaxonomyPoiType` a ogni rename, rompendo i riferimenti
  persistiti nella config App (`src/Nova/App.php:923-941`). Con la regola finale
  il ricalcolo è comunque idempotente, quindi il guard non serve toglierlo.

- **Migration unica invece che in due passi.** Il piano prevedeva colonna+backfill
  e unique separati per non bloccare la deploy di altri consumer. Il dev ha
  confermato che `TaxonomyWhere` è usata solo in forestas e che non esiste una
  produzione: rischio teorico, migration unica.

- **Backfill in SQL puro.** Confermato: `TaxonomyWhere` estende `Polygon`, e
  risalvare i modelli via Eloquent farebbe transitare la geometria PostGIS
  attraverso l'ORM.

- **Nomi bilingui accettati.** Alcuni comuni arrivano come `Ula/Ulà Tirso`,
  `Nughedu Santu Nigola/Nughedu San Nicolò`: è il tag `name` base di OSM, che per
  quei comuni contiene sardo e italiano separati da `/`. Accade solo dove manca
  `name:it`, che altrimenti ha la precedenza. Lasciati così.

- **Bypass del gate PHPStan (approvato dal dev, 2026-09-03).** Il workflow blocca
  il commit in presenza di errori PHPStan sui file del diff. Ce ne sono 7, ma
  sono **gli stessi 7 identici prima e dopo** le modifiche: verificato con doppia
  esecuzione sugli stessi quattro file, mettendo da parte le modifiche con
  `git stash` fra le due. Nessun errore introdotto da questo ticket; il progetto
  ne ha 980 in totale. Bypass confermato esplicitamente dal dev, che se ne assume
  la responsabilita'.

## Follow-up

- **Licenza Nova scaduta.** `wm-package` non era installabile: il suo
  `composer.lock` (non tracciato, generato localmente a marzo) pinnava
  `laravel/nova 5.8.0`, che la licenza non copre più — tutte le versioni dalla
  5.8.0 in poi rispondono **HTTP 402**, la 5.7.6 risponde 200. Il pannello Nova
  conferma: *"License Expired … install the last version available to you with:
  composer require laravel/nova:5.7.6"*. Risolto in locale allineando a 5.7.6
  (`composer.json` ripristinato a `^5.0`, nessun file tracciato modificato).
  **Il rinnovo diventa obbligatorio prima di passare a Laravel 13**, il cui
  supporto Nova arriva in 5.8.0.

- **Suite del package non eseguibile per intero.** 30 file di test usano
  `use Tests\TestCase;` (la classe del progetto consumer, convenzione
  camminiditalia) e altri hanno un `uses(TestCase::class)` ridondante in
  conflitto col binding globale di `tests/Pest.php`. Preesistente, indipendente da
  questo ticket. I test di questa feature girano isolati e passano (21).

- **PHPStan: 980 errori nel package**, invariati dai nostri file (7 prima, 7 dopo
  sugli stessi 4 file). Debito preesistente, verosimilmente amplificato dal
  mismatch fra codice scritto per Nova 5.8 e vendor 5.7.6.

- **Correzione una tantum dei nomi già in DB.** I 58 record importati prima del
  fix sono stati corretti con uno script una tantum da tinker (non committato):
  il job non li avrebbe sistemati da solo, perché per i nomi non latini salvati
  sotto la chiave `it` il controllo `hasReliableName` risulta `true`. Altri
  progetti che dovessero trovarsi nella stessa condizione avrebbero bisogno di un
  comando artisan dedicato — non incluso qui perché forestas è l'unico consumer.

- **DB di test del package creato ex novo** (`wm_package`, con PostGIS), separato
  da `forestas` come richiesto da `phpunit.xml.dist`.
