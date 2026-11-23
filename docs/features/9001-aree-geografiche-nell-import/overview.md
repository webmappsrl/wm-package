> Ticket: oc:9001

# Colori degli overlay e creazione del Layer da una Taxonomy Where

## Cosa cambia

1. **Colori degli overlay.** I colori di riempimento e di bordo di una FeatureCollection salvati
   con la trasparenza (`#RRGGBBAA`, per esempio `#a61c0066`) arrivano al `config.json` dell'app
   come `rgba(…)` con la loro opacità, invece di essere sostituiti dal colore principale dell'app
   (e, se quello non è impostato, dal nero).
2. **Creazione del Layer da una Taxonomy Where richiamabile da codice.** La logica dell'action
   Nova «Crea Layer» (`CreateLayerFromTaxonomyWhere`: nome del layer, app, utente, collegamento
   alla Taxonomy Where, immagine) passa in un metodo di `LayerService` che l'action richiama. Il
   metodo copia nel layer anche la descrizione e il sottotitolo della Taxonomy Where, e tutte le
   traduzioni di nome, descrizione e sottotitolo: oggi il layer prende il nome in una sola lingua
   e nessuna descrizione (i layer delle aree catastali di forestas hanno `description:
   {"de": null}`, mentre su Geohub hanno il loro testo).

## Perché

Lo shard forestas porta le aree catastali e le aree geografiche nella fase di import (oc:9001) e
deve creare i loro layer senza passare da Nova: oggi quella logica vive tutta dentro l'action, e
ricopiarla nello shard la farebbe divergere al primo cambio.

Gli overlay di forestas escono neri sulla mappa: tutte le FeatureCollection hanno `fill_color`
con la trasparenza (`#1155cc80`, `#a61c0066`…), e `sanitizeHexColor()` accetta solo 6 cifre. In
`AppConfigService::config_section_map()` il colore viene scartato e sostituito con
`primary_color` dell'app, che su forestas è nullo, quindi `#000000`. `hexToRgba()`, che riceve il
risultato, gestisce già le 8 cifre.

## Requisiti

- [x] In `config_section_map()` un `fill_color` o `stroke_color` di 8 cifre diventa
      `rgba(r, g, b, alpha)` con l'opacità salvata
- [x] I colori di 6 cifre danno lo stesso risultato di oggi; i valori non validi (3 cifre, vuoti,
      testo) ricadono ancora su `primary_color`
- [x] Gli altri usi di `sanitizeHexColor()` (`primary_color` del tema, colore della story share)
      non cambiano comportamento
- [x] Un metodo di service crea il Layer di una Taxonomy Where con la stessa logica dell'action
      (`app_id`, `user_id` dell'app, collegamento, immagine da `feature_image`) e copia nel layer,
      con tutte le traduzioni salvate, il nome (`getTranslations('name')`: oggi l'action legge
      `$taxonomyWhere->name`, che restituisce la sola lingua corrente) e, dalle `properties` della
      Taxonomy Where, `description` e `subtitle`, che sul Layer sono traducibili
      (`Layer::$translatable`)
- [x] L'action «Crea Layer» usa quel metodo: d'ora in poi anche i layer creati da Nova hanno nome
      in tutte le lingue, descrizione e sottotitolo
- [x] Chi chiama il metodo può creare il layer con gli eventi spenti (l'import di forestas lo fa,
      per non accodare job per ogni riga del pivot); l'action lo chiama con gli eventi accesi
- [x] Test sui colori di 6 e 8 cifre e sul metodo di creazione del Layer

## Rischi

- **Il package è condiviso.** Ogni consumer con overlay a 8 cifre vedrà, dopo il bump, i colori
  salvati al posto del colore principale dell'app: è la correzione voluta, ma cambia l'aspetto di
  quegli overlay.

## Out of scope

- Accettare colori a 3 cifre in `sanitizeHexColor()`: `hexToRgba()` li rifiuta con un'eccezione
  (vedi il docblock della funzione).
- Rendere idempotente l'action «Crea Layer»: il controllo «l'area ha già un layer» lo fa il
  consumer.

## Moduli toccati

Repo **wm-package**:

- `src/Services/Models/App/AppConfigService.php` — colori degli overlay in `config_section_map()`
- `src/helpers.php` — se serve, una variante della sanitizzazione che accetta anche 8 cifre
- `src/Services/Models/LayerService.php` — metodo di creazione
  del Layer da una Taxonomy Where
- `src/Nova/Actions/CreateLayerFromTaxonomyWhere.php` — usa il metodo nuovo
- `tests/` — test dei colori e del metodo
