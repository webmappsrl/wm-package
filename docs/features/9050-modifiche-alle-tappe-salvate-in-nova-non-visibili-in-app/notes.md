> Ticket: oc:9050
> Overview approvata il 2026-11-27 · impronta `adedd4f9ba8b631e64b701797f6cc4cf67b64fb2`

# Notes — Modifiche alle tappe salvate in Nova non visibili in app

## Modifiche ai requisiti

### 2026-11-27 — Il ripiego segue la pubblicazione di ogni catena, e la creazione non ha il PBF
- **Requisito:** [R2](overview.md#r2)
- **Effetto:** modificato
- **Decide:** dev (piano approvato il 27/11 con la tabella dei ripieghi; cleanup scelti dopo `wm-review-ticket` il 27/11)
- **Perché:** il testo approvato chiede il PBF in tutte le catene con i job della geometria, ma la
  catena di creazione non esegue il PBF nemmeno quando riesce, e il piano approvato ne escludeva il
  ripiego per rispettare [R3](overview.md#r3). Dalla review sono emerse anche le catene custom di
  `ExecuteEcTrackDataChainAction`, e il caso «Update Tracks on AWS», dove il ripiego rifarebbe lo
  stesso job appena fallito.
- **Scartato:** aggiungere il PBF al ripiego della creazione — farebbe nel ripiego un lavoro che la
  creazione riuscita non fa.
- **Testo nuovo:**
  > Se un job di una catena di EcTrack fallisce, i job di pubblicazione che quella catena esegue quando va a buon fine vengono accodati comunque; nelle catene di aggiornamento, DEM mancante e inversione che contengono i job della geometria, prima della pubblicazione si accoda anche `GenerateEcTrackPBFBatch` della tappa. Una catena senza job di pubblicazione, o fatta solo di quelli, non ha ripiego. Il job fallito resta in `failed_jobs`.

## Divergenze dal piano, task per task

### Task 1: elevation chart fuori dalle catene

2026-11-27. `tests/Feature/OrderListReorderTest.php` non è stato toccato: il piano lo elencava fra
i test da aggiornare, ma lì la classe compare solo nel `Bus::fake([...])` locale, che ripete quello
globale di `tests/Pest.php` e resta per lo stesso motivo del Passo 5. Nessuna `assertChained` lo
cita.

### Task 2: pubblicazione di ripiego quando un job della catena fallisce

2026-11-27. Aggiunto `test_reverse_with_geometry_falls_back_to_pbf_and_publication`, che il piano
non elencava: copre la riga «`reverse()` con geometria» della tabella dei ripieghi.

2026-11-27, dalla review del branch. Anche le catene custom di `ExecuteEcTrackDataChainAction`
(`executeCustomChain()`, per esempio «Regenerate Taxonomy Where» =
`[SyncModelTaxonomyWhereJob, UpdateEcTrackAwsJob]`) passano da `dispatchChainWithFallback()`, che
diventa pubblico: il ripiego sono i job di pubblicazione presenti nella catena; una catena senza
pubblicazione resta senza `catch()`. Il piano non le considerava, ma [R2](overview.md#r2) parla di
ogni catena di EcTrack. Test: `ExecuteEcTrackDataChainActionTest` ›
`test_custom_chain_falls_back_to_its_publication_jobs`, `test_custom_chain_without_publication_jobs_has_no_fallback`.

## Bug trovati

- 2026-11-27 — `UpdateEcTrackGenerateElevationChartImage` fallisce in produzione dal 20/10: `canvas.node`
  compilato per `NODE_MODULE_VERSION 108` (Node 18), container `wm-phpfpm:8.4` su Node 20.19.2. Il
  job è uscito dalle catene, il modulo non è stato ricompilato (out of scope).
- 2026-11-27 — Preesistente, non toccato: `ReverseTest` › «dispatches the chain even if reindexing
  fails» fallisce già su `develop` (`warning()` su null in `EcTrackFactory.php:58`);
  `AppImportedPropertiesFieldsTest` (2 test) fallisce lanciato dal package per
  `Outl1ne\MultiselectField\Multiselect` mancante nel vendor.

## Decisioni

### 2026-11-27 — Tag Orchestrator non associati
- Il dev ha chiesto di saltare la proposta dei tag: il ticket resta con `26Q4` e `ass_cammini_italia`.

### 2026-11-27 — Minori della review del branch non corretti
- Se fallisce un job di pubblicazione, il ripiego ripete anche quelli già riusciti (un PBF e una
  scrittura AWS in più): il `catch()` riceve solo l'eccezione, non quale job è fallito. Accettato.
- Il test di serializzazione fa solo `serialize()`; il ciclo `unserialize` + invocazione l'ha
  verificato a mano il reviewer nel container. Non rafforzato.
- `fallbackJobClasses()` istanzia i job di `publicationJobs()` per leggerne la classe: innocuo.
- **Scartato:** correggerli in questo ciclo — nessuno cambia il comportamento verso l'app.

### 2026-11-27 — Cleanup scelti dopo `wm-review-ticket`
- Fatti: voce su [R2](overview.md#r2) qui sopra; regola in `.claude/rules/job-e-import.md` limitata
  alle catene che pubblicano; avviso `@internal` su `dispatchFallbackJobs()` (nome serializzato nei
  payload); test di serializzazione con `unserialize` e invocazione del `catch()`; tolta da
  `UpdateDataChainTest` un'asserzione `assertNotDispatched` che non poteva fallire; niente ripiego
  per una catena custom fatta solo di pubblicazione (`test_custom_chain_made_only_of_publication_jobs_has_no_fallback`).
- **Scartato:** ricavare le liste di ripiego dalla catena e spostare la regola dell'action nel
  service (refactor di struttura), log quando scatta il ripiego (fallimenti silenziosi accettati),
  `@deprecated` sulla classe dell'elevation chart, inversione PBF/pubblicazione nel ripiego
  (`GenerateEcTrackPBFBatch` mai fallito in produzione: 0 su 843 `failed_jobs` dal 20/10).

## Follow-up

- Nel log di produzione `HandlesData` scrive a ogni catena con geometria
  `Call to undefined method EcTrack::getDemDataFields()` (390 volte il 20/11): non ferma la catena.
