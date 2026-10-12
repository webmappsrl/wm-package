> Ticket: oc:8672

# Chiavi di traduzione in inglese nel Catasto Sentieri (wm-package) e in forestas

Questa overview copre la parte del package. La parte di forestas è in
`forestas/docs/features/8672-chiavi-di-traduzione-in-inglese-nel-catasto-sentieri-wm-package-e-in-forestas/overview.md`.

## Cosa cambia

Le chiavi di `__()` scritte in italiano nel Catasto Sentieri passano all'inglese. Il testo che
l'operatore legge in italiano resta quello di oggi, perché si sposta nella voce di
`resources/lang/it.json`. Si aggiunge un test che segnala una chiave letterale usata nei percorsi
del Catasto ma assente da `it.json` o da `en.json`.

## Perché

La convenzione Webmapp è la chiave in inglese: così è il resto del package (432 voci su 493 in
`resources/lang/en.json` hanno chiave uguale al valore inglese). Il Catasto è nato con chiavi
italiane con oc:8489, perché la regola traduzioni di `wm-plan` chiedeva il testo base nella
«lingua di default del repo», che in forestas è `it`. La regola è già stata corretta
(webmappsrl/claude-marketplace#23).

Oggi la situazione non è uniforme: alcune chiavi italiane hanno la voce in `en.json` con valore
inglese (`"Sostituisci numero": "Replace number"`), la maggior parte no («Istanze», «Registro dei
codici», «Catasto») e compare in italiano con qualunque lingua.

## Requisiti

- [ ] Nessuna chiave di `__()` in italiano nei percorsi dello scope:
  - `src/TrailRegistry/` (Resource, Action, renderer, Model, Concerns)
  - in `src/WmPackageServiceProvider.php`, le chiavi del Catasto (voce di menu «Catasto»,
    «Registro dei codici», le voci del menu del Catasto)
  - fuori dal Catasto, solo le tre indicate dal ticket: `src/Nova/EcPoi.php:65`,
    `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php:136`, `src/Nova/TaxonomyWhere.php:53`
- [ ] Testo della chiave inglese:
  - se la chiave italiana ha già un valore inglese in `en.json`, quel valore diventa la chiave
  - altrimenti si usa la terminologia delle classi del dominio («Trail registry»,
    «Applications», «Code registry», «Anomalies»), in un inglese naturale; dove il nome della
    classe darebbe un inglese forzato si usa l'inglese corretto e si annota in `notes.md`
  - «Catasto» diventa `Trail registry`, come chiede il ticket: oggi compare in italiano con
    qualunque lingua. In inglese convivono tre nomi vicini, ognuno per una cosa diversa:
    «Trail registry» (il Catasto), «Code registry» (il registro dei codici), «Registry» (in
    forestas, il foglio del registro catastale)
  - le scelte sono nella sezione «Tabella delle chiavi» in fondo
- [ ] Controllo collisioni: una chiave inglese nuova non può esistere già, nei JSON del package o
  in `forestas/lang/`, con un testo italiano diverso da quello di oggi. Esempio: «Denominazione»
  non può diventare `Name`, perché `it.json:109` ha già `"Name": "Nome"`; serve una chiave più
  specifica (per esempio `Designation`). Il controllo è stato fatto sulla tabella in fondo con
  `token_get_all` sui JSON del package e di forestas: nessuna collisione
- [ ] Per ogni chiave nuova: voce in `resources/lang/it.json` con il testo italiano di oggi, voce
  in `resources/lang/en.json` con la chiave stessa. Mai in `lang/`, che il package non carica
- [ ] La voce della chiave italiana sostituita viene tolta da `it.json` e `en.json`, non lasciata
  accanto alla nuova
- [ ] Con `APP_LOCALE=it` l'operatore vede esattamente i testi italiani di oggi. Unica eccezione
  accettata: le chiavi generiche `Details`, `Detail`, `Status` e `Source` (vedi Rischi)
- [ ] Le etichette italiane nelle costanti `ENTRIES` e `SIGNS` di `MapLegendRenderer.php`
  (righe 31-35 e 115-118), passate a `__($label)` alle righe 81 e 167, diventano chiavi inglesi
  come le altre
- [ ] I test esistenti che usano le chiavi italiane passano con le chiavi nuove. Da una ricerca
  non esaustiva, in `tests/Feature/TrailRegistry/`: `TrailApplicationCreateFormTest`,
  `TrailRegistryShardResourcesTest`, `ReplaceTrailCodeNumberActionTest`, `MapLegendRendererTest`,
  `TrailRegistryNovaResourcesTest`, `MainMenuInjectionTest`, `MenuSectionInjectionTest`,
  `TrailRegistryMapFieldTest`, `TrailRegistryAnomalyTypesTest`, `TrailRegistryCodeMapTest`; fuori,
  `tests/Feature/Nova/Actions/ImportTaxonomyWhereGeohubSourceTest.php`
- [ ] Test nuovo sui percorsi dello scope, dichiarati in cima al test (allargarli è un lavoro a
  parte):
  - ogni chiave convertita (quelle della «Tabella delle chiavi») ha la voce in `it.json` e in
    `en.json`;
  - le altre chiavi letterali senza voce, già inglesi (per esempio in `src/Nova/EcPoi.php`
    `EC Poi`, `Info`, `Description`, `Contact email`; `DEM` in `TrailApplication.php:181`), sono
    elencate nell'esito, non verificate: oggi l'operatore le legge in inglese e il ticket non le
    tocca;
  - per le chiavi convertite, il valore in `it.json` è identico al testo italiano di oggi,
    confrontato con un elenco «chiave inglese → testo italiano» scritto nel test. L'elenco si
    ricava dal codice di oggi, prima di modificarlo, cioè dalla «Tabella delle chiavi»: mai da
    `it.json` già modificato, altrimenti il test confronta il file con sé stesso. È l'unica
    garanzia sul testo italiano: i test esistenti confrontano `__('chiave')` con `__('chiave')` e
    girano in CI con `en`;
  - le chiavi si estraggono con il tokenizer di PHP (`token_get_all`), non con una regex: così
    sono lette correttamente le chiamate `__(` su più righe e gli apici con escape;
  - le etichette delle costanti di `MapLegendRenderer` si verificano leggendo le costanti, perché
    il tokenizer vede solo `__($label)`; le altre chiamate con chiave dinamica sono elencate
    nell'esito, non verificate
- [ ] Le chiavi usate anche da forestas (`Catasto`, `Sentiero` e le altre in comune) hanno in
  forestas lo stesso testo inglese: la tabella «chiave italiana → chiave inglese» del package è
  la fonte per forestas

## Rischi

- **Una chiave dimenticata in `it.json` mostra l'inglese all'operatore senza nessun errore.**
  Mitigazione: il test nuovo sui percorsi dello scope.
- **Oggi le chiavi italiane mostrano l'italiano con qualunque `APP_LOCALE`; dopo il ticket la
  lingua dipende davvero da `APP_LOCALE`.** Verificato: UAT, che è anche la produzione di
  forestas (l'host su cui `prod-deploy.yml` fa il deploy di `main`), ha `lang="it"` nella pagina di login di Nova (`Nova::resolveUserLocale()`). La CI gira
  con `en` (`.env-deploy:49`). Se forestas avrà un giorno una produzione separata, il suo `.env`
  deve avere `APP_LOCALE=it`.
- **Collisioni fra chiavi.** Una chiave JSON ha un solo valore per lingua: una chiave inglese già
  presente con un altro testo italiano cambierebbe quello che legge l'operatore. Mitigazione: il
  controllo collisioni nei requisiti e il confronto sul testo italiano nel test.
- **La sezione di menu «Catasto» si trova per etichetta tradotta.** `WmPackageServiceProvider.php:335`
  scarta le sezioni con `$sectionOrGroup->name !== $sectionName`, dove `$sectionName` è
  `__('Catasto')` (righe 807 e 844). Se package e forestas usassero chiavi diverse, con lingua
  inglese la sezione si sdoppierebbe. Mitigazione: forestas porta la sua chiave a `Trail registry`
  nella PR in cui aggiorna il submodule, e ha un test che in inglese verifica una sola sezione del
  Catasto.
- **Conflitti con oc:8567 sui file di lingua.** oc:8567 è già entrato in `develop`
  (wm-package#292, `bdd9c059`): il branch di questo ticket parte da lì, e le voci inglesi di
  `ApproveTrailApplication` e `RejectTrailApplication` sono già nei JSON.
- **Le traduzioni del package arrivano a ogni shard che lo monta, quando aggiorna il
  submodule.** Alcune chiavi nuove sono già usate fuori dal Catasto senza voce italiana, e con la
  voce aggiunta cambia anche il testo italiano di quelle schermate, che oggi compare in inglese:
  - `Details` → «Dettagli»: tab di `src/Nova/EcTrack.php:69`, `src/Nova/EcPoi.php:72` e
    `src/Nova/AbstractEcResource.php:230`, il gruppo di tab ereditato da tutte le resource EC;
  - `Detail` → «Dettaglio»: intestazione del report di `src/Nova/Actions/UploadPoiFile.php`
    (righe 57, 66, 200, 247);
  - `Status` → «Stato»: pannello di `src/Nova/FeatureCollection.php:119`;
  - `Source` → «Provenienza»: pannello di `src/Nova/FeatureCollection.php:54`.

  `Proprietà` → `Properties` (`src/Nova/TaxonomyWhere.php:53`) invece non cambia nulla in
  italiano (`it.json:148` ha già la voce), mentre con lingua inglese il pannello passa da
  «Proprietà» a «Properties». È voluto: l'obiettivo sono traduzioni coerenti in tutta la
  piattaforma. Va scritto nella descrizione della PR del package.

## Out of scope

- Le chiavi delle azioni `ApproveTrailApplication` e `RejectTrailApplication`: le porta in inglese
  oc:8567
- Le chiavi italiane del package fuori dai percorsi dello scope (per esempio
  `AbstractGeometryResource`, `ExportTo`, `AddLayersToConfigHomeAction`)
- Le ~150 chiavi già inglesi ma senza voce nei JSON, fuori dal Catasto
- Le stringhe italiane passate senza `__()`
- La revisione dei testi italiani: cambia solo la chiave

## Moduli toccati

Tutti in `wm-package`:

- `src/TrailRegistry/Nova/Actions/ReplaceTrailCodeNumber.php`
- `src/TrailRegistry/Nova/TrailApplication.php`, `TrailRegistryCode.php`, `TrailRegistryAnomaly.php`
- `src/TrailRegistry/Nova/AnomalyDetailRenderer.php`, `AnomalyMapLegendRenderer.php`, `MapLegendRenderer.php`
- `src/TrailRegistry/Models/TrailRegistryCode.php`, `TrailRegistryAnomaly.php`, `Concerns/ComposesTrailRegistryMap.php`
- `src/WmPackageServiceProvider.php`
- `src/Nova/EcPoi.php`, `src/Nova/TaxonomyWhere.php`, `src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php`
- `resources/lang/it.json`, `resources/lang/en.json`
- i test esistenti che usano le chiavi italiane, elencati nei requisiti
- un test nuovo in `tests/`

Il branch parte da `origin/develop`, che contiene già oc:8567. Si fa il merge prima qui, poi in
forestas.

## Tabella delle chiavi

80 chiavi distinte, estratte con `token_get_all` su `develop` del 06/10 (dopo il merge di
oc:8567). «Dove» è il percorso sotto `src/` o `src/TrailRegistry/`. Le chiavi in comune con
forestas (`Catasto`, `Sentiero`, `Settore`, `Numero`, …) hanno la stessa chiave inglese nei due
repo.

| Chiave italiana | Chiave inglese | Dove |
|---|---|---|
| già importata | already imported | `Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php:136` |
| Tipologie di POI associate a questo punto di interesse | POI types associated with this point of interest | `Nova/EcPoi.php:65` |
| Proprietà | Properties | `Nova/TaxonomyWhere.php:53` |
| Settore | Sector | `Nova/TrailRegistryCode.php:209`, `Models/Concerns/ComposesTrailRegistryMap.php:87` |
| dalla geometria | from the geometry | `Models/TrailRegistryAnomaly.php:176` |
| dichiarato dal codice | declared by the code | `Models/TrailRegistryAnomaly.php:239` |
| scelto | chosen | `Models/TrailRegistryCode.php:279`, `Models/TrailRegistryCode.php:306`, `Models/TrailRegistryCode.php:333` |
| Sentiero | Trail | `Nova/TrailRegistryCode.php:199`, `Nova/TrailRegistryAnomaly.php:227`, `Models/TrailRegistryCode.php:414`, `Models/TrailRegistryCode.php:446` |
| Istanza | Application | `Nova/TrailApplication.php:74`, `Nova/TrailRegistryCode.php:187`, `Models/TrailRegistryCode.php:476` |
| Istanze | Applications | `Nova/TrailApplication.php:69`, `WmPackageServiceProvider.php:465` |
| Sostituisci numero | Replace number | `Nova/Actions/ReplaceTrailCodeNumber.php:46` |
| Questa istanza non ha un codice attivo da sostituire. | This application has no active code to replace. | `Nova/Actions/ReplaceTrailCodeNumber.php:58` |
| Numero sostituito. | Number replaced. | `Nova/Actions/ReplaceTrailCodeNumber.php:72` |
| Numero | Number | `Nova/TrailRegistryCode.php:210`, `Nova/Actions/ReplaceTrailCodeNumber.php:78` |
| Variante | Variant | `Nova/TrailRegistryCode.php:211`, `Nova/Actions/ReplaceTrailCodeNumber.php:82` |
| nessuna variante | no variant | `Nova/Actions/ReplaceTrailCodeNumber.php:144` |
| Codice | Code | `Nova/TrailApplication.php:118`, `Nova/TrailRegistryCode.php:174`, `Nova/AnomalyDetailRenderer.php:48` |
| Già assegnato a | Already assigned to | `Nova/AnomalyDetailRenderer.php:49` |
| Codice nei dati | Code in the data | `Nova/AnomalyDetailRenderer.php:52`, `Nova/AnomalyDetailRenderer.php:59`, `Nova/AnomalyDetailRenderer.php:63` |
| Codice dalla geometria | Code from the geometry | `Nova/AnomalyDetailRenderer.php:53` |
| Stessa traccia di | Same track as | `Nova/AnomalyDetailRenderer.php:56` |
| Primo libero nel settore | First free number in the sector | `Nova/AnomalyDetailRenderer.php:60` |
| Tipo | Type | `Nova/AnomalyDetailRenderer.php:92`, `Nova/TrailRegistryAnomaly.php:171` |
| Contesto | Context | `Nova/AnomalyDetailRenderer.php:93` |
| Apri su :platform | Open on :platform | `Nova/AnomalyDetailRenderer.php:138` |
| Apri la scheda sulla piattaforma di origine | Open the record on the source platform | `Nova/AnomalyDetailRenderer.php:139` |
| assente | missing | `Nova/AnomalyDetailRenderer.php:182` |
| Settore in cui la traccia ricade | Sector the track falls in | `Nova/AnomalyMapLegendRenderer.php:57` |
| Altri settori: quelli attraversati e quello dichiarato dal codice | Other sectors: those crossed and the one declared by the code | `Nova/AnomalyMapLegendRenderer.php:65` |
| Altri settori attraversati, con la percentuale di percorso | Other sectors crossed, with the percentage of the route | `Nova/AnomalyMapLegendRenderer.php:66` |
| Profilo altimetrico sotto la mappa: del sentiero | Elevation profile below the map: of the trail | `Nova/MapLegendRenderer.php:95` |
| Profilo altimetrico sotto la mappa: della traccia dell'istanza | Elevation profile below the map: of the application track | `Nova/MapLegendRenderer.php:96` |
| Nessuna geometria da mostrare per questo codice. | No geometry to show for this code. | `Nova/MapLegendRenderer.php:101` |
| Settore da cui viene il prefisso | Sector the prefix comes from | `MapLegendRenderer.php (costante)` |
| Altri sentieri del settore, con numero e variante | Other trails in the sector, with number and variant | `MapLegendRenderer.php (costante)` |
| Sentiero a cui il codice e' assegnato | Trail the code is assigned to | `MapLegendRenderer.php (costante)` |
| Traccia dell'istanza da cui il codice e' nato | Application track the code originated from | `MapLegendRenderer.php (costante)` |
| Numero di questo codice | Number of this code | `MapLegendRenderer.php (costante)` |
| Numero liberato: non appartiene piu' a questa istanza | Released number: no longer belongs to this application | `MapLegendRenderer.php (costante)` |
| Numero di un sentiero validato | Number of a validated trail | `MapLegendRenderer.php (costante)` |
| Numero proposto da un'altra istanza | Number proposed by another application | `MapLegendRenderer.php (costante)` |
| Denominazione | Designation | `Nova/TrailApplication.php:116`, `Nova/TrailApplication.php:222`, `Nova/TrailRegistryCode.php:178` |
| Stato istruttoria | Review status | `Nova/TrailApplication.php:120` |
| Provenienza | Source | `Nova/TrailApplication.php:122`, `Nova/TrailRegistryCode.php:184` |
| Inserita da | Entered by | `Nova/TrailApplication.php:131`, `Nova/TrailApplication.php:132` |
| Presentata il | Submitted on | `Nova/TrailApplication.php:134` |
| Mappa | Map | `Nova/TrailApplication.php:166`, `Nova/TrailRegistryCode.php:229`, `Nova/TrailRegistryAnomaly.php:201` |
| Legenda | Legend | `Nova/TrailApplication.php:168`, `Nova/TrailRegistryCode.php:234`, `Nova/TrailRegistryAnomaly.php:204` |
| File GPX/GeoJSON caricato | Uploaded GPX/GeoJSON file | `Nova/TrailApplication.php:174` |
| Dettagli | Details | `Nova/TrailApplication.php:180` |
| Geometria (GPX o GeoJSON) | Geometry (GPX or GeoJSON) | `Nova/TrailApplication.php:224` |
| Il tracciato del sentiero: un GPX (traccia o rotta) oppure un GeoJSON con una o piu' linee. | The trail route: a GPX (track or route) or a GeoJSON with one or more lines. | `Nova/TrailApplication.php:227` |
| anomalia | anomaly | `Nova/TrailRegistryAnomaly.php:79` |
| Anomalie | Anomalies | `Nova/TrailRegistryAnomaly.php:109`, `WmPackageServiceProvider.php:467` |
| Anomalia | Anomaly | `Nova/TrailRegistryAnomaly.php:114` |
| Dettaglio | Detail | `Nova/TrailRegistryAnomaly.php:182` |
| Sentiero collegato | Linked trail | `Nova/TrailRegistryAnomaly.php:190` |
| Rilevata il | Detected on | `Nova/TrailRegistryAnomaly.php:195` |
| Codice già assegnato | Code already assigned | `Nova/TrailRegistryAnomaly.php:265` |
| Settore discordante | Sector mismatch | `Nova/TrailRegistryAnomaly.php:266` |
| Geometria duplicata | Duplicate geometry | `Nova/TrailRegistryAnomaly.php:267` |
| Codice illeggibile | Unreadable code | `Nova/TrailRegistryAnomaly.php:268` |
| Fuori da ogni settore | Outside any sector | `Nova/TrailRegistryAnomaly.php:269` |
| Che cosa sono queste righe | What these rows are | `Nova/TrailRegistryAnomaly.php:278` |
| Registro dei codici | Code registry | `Nova/TrailRegistryCode.php:141`, `WmPackageServiceProvider.php:466` |
| Codice del registro | Registry code | `Nova/TrailRegistryCode.php:146` |
| Stato | Status | `Nova/TrailRegistryCode.php:180` |
| Regione | Region | `Nova/TrailRegistryCode.php:206` |
| Provincia | Province | `Nova/TrailRegistryCode.php:207` |
| Settore di riferimento | Reference sector | `Nova/TrailRegistryCode.php:220` |
| Storia dei cambi di stato | Status change history | `Nova/TrailRegistryCode.php:239` |
| Sono i sentieri che <strong>non hanno ottenuto un numero</strong>, e il motivo per cui non l’hanno ottenuto. Nel registro dei codici entrano solo i codici su cui non pende alcun dubbio: tutto ciò che resta irrisolto sta qui, e finché sta qui quel sentiero è senza numero. | These are the trails that <strong>did not get a number</strong>, and the reason why. Only codes with no doubt pending enter the code registry: everything still unresolved is here, and while it is here that trail has no number. | `Nova/TrailRegistryAnomaly.php:256` |
| Le correzioni <strong>non si fanno da questa schermata</strong>: il dato appartiene alla piattaforma di origine. Ogni nome di sentiero porta due collegamenti — il <strong>nome</strong> apre la sua scheda qui, l’<strong>icona</strong> accanto quella sulla piattaforma di origine: si corregge lì, e l’importazione successiva fa sparire la riga da sé, assegnando il numero se nel frattempo è diventato assegnabile. | Corrections <strong>are not made from this screen</strong>: the data belongs to the source platform. Each trail name carries two links — the <strong>name</strong> opens its record here, the <strong>icon</strong> next to it the one on the source platform: correct it there, and the next import removes the row on its own, assigning the number if it has meanwhile become assignable. | `Nova/TrailRegistryAnomaly.php:260` |
| Nella colonna <strong>Sentiero</strong> c’è sempre quello rimasto senza numero; nel <strong>Dettaglio</strong> il perché, con accanto l’altro termine del problema — un sentiero quando quel codice ce l’ha già qualcun altro o la traccia è condivisa, un codice quando il problema sta nel dato. | The <strong>Trail</strong> column always shows the one left without a number; the <strong>Detail</strong> shows why, next to the other side of the problem — a trail when someone else already has that code or the track is shared, a code when the problem is in the data. | `Nova/TrailRegistryAnomaly.php:262` |
| Quel codice ce l’ha già un altro sentiero: uno dei due va corretto alla fonte. | Another trail already has that code: one of the two must be corrected at the source. | `Nova/TrailRegistryAnomaly.php:265` |
| Il settore scritto nel codice non è quello in cui la traccia ricade davvero. Accanto, il codice che la piattaforma comporrebbe dalla geometria. | The sector written in the code is not the one the track actually falls in. Next to it, the code the platform would compose from the geometry. | `Nova/TrailRegistryAnomaly.php:266` |
| Due o più sentieri con la stessa identica traccia. Finché non si sa quale sia quello buono, nessuno di loro prende un numero. | Two or more trails with the exact same track. Until it is known which one is right, none of them gets a number. | `Nova/TrailRegistryAnomaly.php:267` |
| Dal campo del codice non si ricava un numero valido. Accanto, il primo numero libero del settore: è un’indicazione, non una decisione — il numero giusto è quello sulla segnaletica in campo. | No valid number can be derived from the code field. Next to it, the first free number in the sector: it is a hint, not a decision — the right number is the one on the signage in the field. | `Nova/TrailRegistryAnomaly.php:268` |
| La geometria non ricade in alcun settore, quindi non esiste un prefisso con cui comporre il codice. | The geometry does not fall in any sector, so there is no prefix to compose the code with. | `Nova/TrailRegistryAnomaly.php:269` |
| Catasto | Trail registry | `WmPackageServiceProvider.php:807`, `WmPackageServiceProvider.php:844` |
