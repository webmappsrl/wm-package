> Ticket: oc:8981

# Avvisi dei sentieri: data dell'avviso su EcTrack

Parte del wm-package. Label, posizione in Nova e API per il sito sono in forestas
(`docs/features/8981-avvisi-dei-sentieri/overview.md` del repo forestas).

## Cosa cambia

`EcTrack` registra in automatico la data in cui un avviso viene pubblicato, in
`properties->not_accessible_updated_at` (ISO 8601). La Resource Nova `EcTrack` la mostra in sola
lettura accanto a `not_accessible` e `not_accessible_message`, nel tab Info.

## Perché

Il sito di Forestas mostra la data di pubblicazione dell'avviso e ordina l'elenco degli avvisi per
data. Allo scrum del 17/11 la data di creazione è stata scartata, perché cambia a ogni modifica
della traccia. Si è deciso di aggiungerla nel wm-package e non solo in forestas, perché è un dato
utile a chiunque usi `not_accessible`.

## Requisiti

- [ ] La data si scrive quando `not_accessible` passa da spento (assente, `false`) ad acceso.
- [ ] La data si scrive quando cambia il testo italiano di `not_accessible_message`.
- [ ] La data non cambia quando l'avviso si spegne, né quando cambiano solo le altre traduzioni del
      messaggio, né per qualunque altra modifica della traccia.
- [ ] La regola è un metodo del modello che confronta le `properties` originali con le nuove. Lo
      chiamano l'evento `saving` (registrato con `static::saving()` in `booted()`, perché valga
      anche per le sottoclassi configurate in `ec_track_model`) e `EcTrackFromSpreadsheet` subito
      prima del `saveQuietly()`.
- [ ] Il confronto tratta allo stesso modo un messaggio stringa (scritto dall'upload Excel) e un
      messaggio `{"it": …}` (scritto da Nova), e un valore di `not_accessible` booleano, numerico o
      stringa: un salvataggio che non cambia il testo non sposta la data.
- [ ] La data si salva in UTC.
- [ ] La data non dipende dal flag del dominio Catasto: `not_accessible` esiste anche senza.
- [ ] Nella Resource Nova `EcTrack`, campo data in sola lettura nel tab Info, sotto il messaggio,
      con label tradotta in tutte le lingue del package (de, en, es, fr, it).
- [ ] La data esce nel JSON del dettaglio della traccia, come il resto di `properties`.
- [ ] Test sul modello per i casi sopra.

## Rischi

- **Un salvataggio che non passa dagli eventi non scrive la data.** Nel package ci sono due
  percorsi che scrivono `not_accessible` con `saveQuietly()`: l'upload Excel (`UploadTrackFile` →
  `EcTrackFromSpreadsheet`), gestito chiamando la regola prima del salvataggio, e l'import da GeoHub
  (`GeohubImportService::persistQuietly()`), che resta un limite dichiarato: Forestas non lo usa, e
  in più il suo `fill()` sostituisce le `properties` cancellando la data.
- **Correggere il testo italiano sposta la data**: è voluto (scrum 17/11), la data è l'ultimo
  aggiornamento del testo.
- **Gli avvisi accesi prima di questa modifica non hanno data**: in forestas nessuna delle 769
  tracce locali ha `not_accessible` valorizzato. Negli altri shard l'API o il frontend devono
  gestire la data assente.

## Out of scope

- Storico degli avvisi, più avvisi per sentiero, scadenza.
- Una data «ufficiale» (per esempio di un'ordinanza): va scritta nel testo del messaggio.

## Moduli toccati

- `src/Models/EcTrack.php` — regola della data e registrazione in `saving`
- `src/Imports/EcTrackFromSpreadsheet.php` — chiamata della regola prima del `saveQuietly()`
- `src/Nova/EcTrack.php` — campo data in sola lettura
- `resources/lang/{de,en,es,fr,it}.json` — label
- `tests/` — test sul modello
- `docs/` — documentazione della Resource EcTrack, se tratta `not_accessible`
