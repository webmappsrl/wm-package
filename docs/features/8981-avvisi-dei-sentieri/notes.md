> Ticket: oc:8981

# Notes — Avvisi dei sentieri: data dell'avviso su EcTrack

Il piano e le note complete stanno nel repo forestas, in
`docs/features/8981-avvisi-dei-sentieri/plan.md` e `notes.md`: il lavoro è stato pianificato ed
eseguito dallo shard.

## Deviazioni dal piano

- La chiamata in `EcTrackFromSpreadsheet` resta, ma con i `validHeaders` di default
  (`config/wm-excel-ec-import.php`) l'upload non scrive `not_accessible`: serve agli shard che
  aggiungono quelle colonne.

## Follow-up

- La suite completa del package non si carica dal container di forestas: 96 file di test usano
  `use Tests\TestCase;` mentre l'autoload mappa solo `Wm\WmPackage\Tests\`. Preesistente.
- L'import da GeoHub non scrive la data e con `fill()` la cancella: limite dichiarato.
