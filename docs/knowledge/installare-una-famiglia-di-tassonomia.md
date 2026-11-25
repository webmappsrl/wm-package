# Accendere una famiglia di tassonomia senza spegnere le altre

## Come funziona oggi

Come si installa una famiglia di classificazioni insieme a un dominio opzionale sta già in due posti:
i tre passi e il loro perché nel docblock di `WalkabilityTaxonomyInstaller` e di
`PrimaryActivityTaxonomyInstaller` (`src/TrailRegistry/Services/`), e l'effetto per chi bumpa uno
shard in [docs/resources/TrailRegistry.md](../resources/TrailRegistry.md). Qui c'è solo quello che non
sta altrove: **come si accende la famiglia sulle app**, che è il punto in cui si sbaglia.

`App::syncTaxonomiesForModel($model, $ids)` (`src/Models/App.php:117`) **toglie `$model` da ogni
tassonomia dell'app** e lo rimette solo a quelle elencate in `$ids`. Una tassonomia che resta senza
modelli viene esclusa dal `sync` finale, cioè **staccata dall'app**. Da qui discendono due errori
opposti, tutti e due reali:

- **passare tutti gli id delle tassonomie dell'app** accende il modello anche su famiglie dedicate ad
  altro: una `["EcPoi"]` arriva a due modelli su due e il pivot salva `models = null`, che significa
  «tutti i contenuti». È quello che `WalkabilityTaxonomyInstaller` fa ancora oggi;
- **passare il solo id della famiglia nuova** — la correzione che sembra naturale della precedente —
  **stacca dall'app tutte le altre.** Simulata sui dati di forestas prima del rilascio: dodici
  famiglie, fra cui le difficoltà, la percorribilità e la validazione, sparite in un colpo.

La regola giusta è: **le tassonomie che hanno già quel modello, o che hanno `models` nullo, più la
famiglia nuova.** Una già accesa resta accesa; una «tutti i contenuti» viene espansa a entrambi i
modelli, le si toglie e le si rimette quello in questione, e `models` torna `null`; una dedicata a un
altro modello non è nell'elenco e resta com'era.
`PrimaryActivityTaxonomyInstaller::enableForExistingApps()` la implementa, con un test per ciascuno
dei tre casi.

**Accendere una famiglia non la rende un filtro.** Il dato entra in `properties.taxonomies` del
dettaglio, nei `LABELS` del `config.json` e nell'indice di ricerca, ma i filtri dell'app e del sito
sono elenchi scritti a mano in ciascun frontend: una famiglia nuova diventa un criterio di ricerca
solo se qualcuno ce la aggiunge.

## Perché così

- **I nomi dei termini si copiano dalle entità già presenti sullo shard, non si scrivono nel package**
  (oc:9038): in forestas le attività non si chiamano come il loro identifier — `hiking` è «Alte vie» —
  e nomi fissi nel package darebbero alla stessa cosa due nomi diversi nella stessa pagina.
- **La cardinalità di una famiglia a scelta singola è una convenzione dell'applicazione, non un
  vincolo del database** (oc:8989): l'indice unique del pivot, documentato in
  [8910-tassonomie-cosa-controllare-al-bump-dello-shard.md](8910-tassonomie-cosa-controllare-al-bump-dello-shard.md),
  impedisce di legare due volte lo *stesso* termine, non due termini *diversi* della stessa famiglia.
  A farla rispettare è il campo che scrive — il `Choice` taglia a uno — e, dove serve uno storico,
  `TrailStatusService`.

## Come ci siamo arrivati

- **Passare tutte le tassonomie dell'app a `syncTaxonomiesForModel()`** (oc:8989, superata): conserva
  le accensioni esistenti, ma accende il modello anche sulle famiglie dedicate ad altri contenuti.
- **Passare il solo id della famiglia nuova** (oc:9038, scartata dopo averla scritta e provata):
  sembra la correzione naturale della precedente ed è molto peggio. L'ha intercettata un re-review, e
  la portata è stata misurata simulando la funzione sui dati locali prima di rilasciarla.
