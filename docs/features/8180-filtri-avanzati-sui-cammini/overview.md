> Ticket: oc:8180

# Filtri avanzati sui cammini — primitive generiche (wm-package)

## Cosa cambia

`wm-package` guadagna **solo due enum**, generici e riusabili da altri shard Webmapp a livello di singola traccia. Nessun'altra modifica al package:

- **Enum `OsmWalkingNetwork`** (namespace `Wm\WmPackage\Enums`): case con nomenclatura OSM (`LWN`, `RWN`, `NWN`, `IWN`, dal tag `network=*` per le reti escursionistiche), con metodo `label()` che restituisce l'etichetta tradotta. Il nome espone esplicitamente la provenienza OSM per non essere confuso con la rete dati in contesto mobile app; la semantica "portata" (Locale/Regionale/Nazionale/Internazionale) vive nel docblock e nelle label tradotte. Valore **scelto manualmente** dall'utente, nessuna lettura automatica del tag OSM in questo ciclo.
- **Enum `Season`**: Primavera/Estate/Autunno/Inverno, stesso pattern `label()` traducibile. Anche questo scelto manualmente. Serve a indicare in quale stagione una traccia/cammino è preferibile da percorrere.

Le label vanno in `resources/lang/it.json` e `resources/lang/en.json` (**non** `lang/` — nel package i file reali stanno sotto `resources/lang/`, scrivere altrove produce chiavi silenziosamente non caricate).

### Cosa NON viene modificato (deciso dopo challenge)

- **`GeometryComputationService`**: nessuna modifica. `isRoundtrip()` resta com'è e viene invocato dal consumer passandogli già i due punti corretti (vero inizio e vera fine del cammino), calcolati a monte — così il suo difetto sulle geometrie multi-parte (prende il primo punto di *ogni* parte, quindi confronta due partenze invece di partenza e arrivo) non viene toccato ma nemmeno subito.
- **`UpdateModelWithGeometryTaxonomyWhere`**: nessuna modifica, **nessun parametro `propertyPath`**. Il job fa `saveQuietly()` sul modello ricevuto, quindi non è utilizzabile con un modello temporaneo (inserirebbe una riga). Il consumer chiama direttamente `OsmfeaturesClient::getWheresByGeojson()` con la geometria aggregata e scrive il risultato da sé. Questo elimina l'accoppiamento cross-shard che un parametro sul job avrebbe introdotto: quel job è referenziato in 9 punti del package, e un bug nel nuovo path di scrittura avrebbe potuto corrompere `properties` di EcTrack/EcPoi/Ugc di **tutti** gli shard Webmapp.

## Perché

Il cliente vuole filtrare i cammini per Lunghezza, Durata, Tipologia, Portata, Regione, Temi, Stagioni. Portata e Stagioni sono vocabolari chiusi che avrebbero senso anche su una singola traccia in qualunque shard Webmapp — per questo gli enum stanno nel package. Tutta l'orchestrazione (come i valori vengono calcolati, aggregati e persistiti su un Layer/cammino) è specifica di camminiditalia e vive nel repo principale, perché il concetto "Layer = cammino suddiviso in tappe" è una customizzazione di questo progetto.

## Requisiti

- [ ] Enum PHP `OsmWalkingNetwork` (LWN/RWN/NWN/IWN) con `label()` traducibile e docblock che documenta la provenienza OSM e la semantica "portata"
- [ ] Enum PHP `Season` (4 stagioni) con `label()` traducibile
- [ ] Chiavi di traduzione in `resources/lang/it.json` e `resources/lang/en.json`
- [ ] Nessuna modifica a `GeometryComputationService`
- [ ] Nessuna modifica a `UpdateModelWithGeometryTaxonomyWhere`

## Rischi

- **Solo it/en tradotti**: il progetto gestisce it/en/fr/es/de (`wm-tab-translatable.locales`). Per fr/es/de le label degli enum usciranno come chiave grezza. Decisione consapevole, coerente con precedenti nel package (oc:8239), non un'omissione.
- **Ciclo di vita degli enum**: i valori vengono persistiti come stringhe in `properties` JSON del consumer. Rinominare o rimuovere un case in futuro farebbe esplodere `Enum::from()` con `ValueError` sul detail Nova di ogni layer con il valore legacy. Da mitigare lato consumer con lettura difensiva (`tryFrom()` invece di `from()`).
- **`const` nei trait**: se la logica delle label venisse estratta in un trait, ricordare che PHP supporta costanti nei trait solo da 8.2 mentre `composer.json` del package richiede `>8.1` — vincolo già documentato in `wm-package/CLAUDE.md` (oc:8349).
- **Nessun test di sincronia chiave↔file lingua**: va aggiunto, precedente in oc:8349 (`data-wm-embed-support`).

## Out of scope

- Campi Nova su `EcTrack` per questi enum (nessuno shard lo richiede oggi: qui si costruisce solo la primitiva)
- Lettura automatica del tag OSM `network=*` (il valore è scelto manualmente)
- Qualunque logica di calcolo/aggregazione/persistenza dei filtri (specifica di camminiditalia — vedi overview nel repo principale)
- Correzione del difetto multi-parte di `isRoundtrip()` (aggirato dal consumer, non risolto: da valutare in un ticket dedicato lato package)
- Componente frontend Home in `wm-core` — ciclo separato

## Moduli toccati

- Nuovo `src/Enums/OsmWalkingNetwork.php` (path indicativo, da confermare in piano)
- Nuovo `src/Enums/Season.php`
- `resources/lang/it.json`, `resources/lang/en.json` — label degli enum
- Nuovo test di sincronia chiavi di traduzione
