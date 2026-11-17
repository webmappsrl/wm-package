# Data dell'avviso su EcTrack

Quando `EcTrack` scrive `properties->not_accessible_updated_at`, e cosa non la scrive.

## Come funziona oggi

- La regola sta in `EcTrack::refreshNotAccessibleUpdatedAt()` (vedi il suo docblock): la data, in
  UTC, segue l'accensione del flag e il testo italiano del messaggio.
- La chiama `static::saving()` in `booted()`, così vale anche per le sottoclassi degli shard
  (`ec_track_model`): `EcTrack::observe()` è legato alla classe del package e su una sottoclasse
  non scatta. La chiama anche `EcTrackFromSpreadsheet` prima del `saveQuietly()`.
- Nella Resource Nova del package la data è un campo in sola lettura nel tab Info.

## Cosa non scrive la data

- `saveQuietly()` fuori da `EcTrackFromSpreadsheet` e gli update di massa.
- L'import da GeoHub (`GeohubImportService::persistQuietly()`): in più il suo `fill()` sostituisce
  `properties` e cancella la data.
- L'upload Excel con i `validHeaders` di default (`config/wm-excel-ec-import.php`), che non
  contengono `not_accessible` né `not_accessible_message`: la chiamata serve agli shard che li
  aggiungono.
- Gli avvisi già accesi quando è nato il campo (oc:8981): restano senza data finché non si spengono e
  riaccendono, o finché non cambia il testo italiano.

## Perché così

- **Data dedicata invece di `updated_at`** (oc:8981): la data di modifica cambia a ogni salvataggio
  della traccia, e un elenco di avvisi ordinato per data ne risulterebbe falsato.
- **Legata al testo italiano** (oc:8981): una correzione del testo conta come aggiornamento
  dell'avviso.
