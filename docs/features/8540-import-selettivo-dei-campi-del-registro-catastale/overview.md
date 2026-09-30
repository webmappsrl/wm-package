> Ticket: oc:8540

# Import selettivo dei campi del registro catastale — chiave `via` del sentiero

## Cosa cambia

Le `properties` di un `EcTrack` hanno una chiave nuova, `via`: la meta intermedia del percorso,
stringa libera non traducibile, accanto a `from` e `to`. Il nome segue il tag OSM delle relazioni
`route`, dove `from`, `to` e `via` vanno insieme («An important station stop along the route»,
[Key:via](https://wiki.openstreetmap.org/wiki/Key:via)).

Il pannello «Proprietà» della Resource EcTrack la mostra fra «da» e «a», con etichetta
«meta intermedia» (it) e «Via» (en).

## Perché

Forestas (oc:8540) importa la meta intermedia dal proprio registro catastale. La chiave vive nel
package, come `from` e `to`, perché è un dato generico di un percorso e non una specificità dello
shard.

## Requisiti

- [ ] `EcTrackPropertiesData` ha `public ?string $via = null` come **ultimo** parametro del
  costruttore, così uno shard che passa gli argomenti per posizione non si rompe; `toArray()` la
  omette se nulla, come le altre.
- [ ] `config/wm-ec-track-schema.php` ha la voce `via` fra `from` e `to`: `type` `text`,
  `translatable` false, `label` `['it' => 'meta intermedia', 'en' => 'Via']`.
- [ ] Per gli shard che non valorizzano `via` il pannello mostra la voce vuota, come oggi `from` e
  `to` quando sono vuoti.
- [ ] Test nuovi: `toArray()` omette `via` se nulla e la include se valorizzata; lo schema ha `via`
  fra `from` e `to`. Oggi non esistono test del DTO né dello schema.

## Rischi

Nessun rischio reale emerso dalla challenge. Ipotetici, non gestiti: uno shard che ha pubblicato
`wm-ec-track-schema.php` non vede `via` (oggi nessuno in `geobox2/`); `via` non arriva a
Elasticsearch, all'export Excel né agli import OSM del package, perché nessuno oggi lo usa.

## Out of scope

- L'inversione del verso di percorrenza (`REVERSE_SWAP_PAIRS`): `via` non dipende dal verso.
- Importatori Excel, GeoHub e OSM del package, indice Elasticsearch: non leggono `via`.

## Moduli toccati

- `src/Dto/EcTrackPropertiesData.php`
- `config/wm-ec-track-schema.php`
- `tests/` — test del DTO e dello schema.
