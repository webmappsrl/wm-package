---
paths:
  - "src/Nova/Fields/**"
  - "src/Nova/Cards/**"
  - "src/*/Nova/Fields/**"
---

# Trappole: build e frontend

Si applica quando tocchi la build di un campo o di una card Nova custom (ogni cartella ha il proprio `webpack.mix.js` e `package.json`).

- `externals: { vue: 'Vue' }` nel `webpack.mix.js`, altrimenti il bundle include una seconda copia
  di Vue e il campo non renderizza: nessun errore, il tab appare vuoto.
- `webpack` pinnato **esatto**, mai con `^`, e **non oltre la 5.103**, che è il valore verificato:
  la 5.111 non compila perché `laravel-mix@6` cerca `webpack/lib/SizeFormatHelpers`, rimosso in
  mezzo. Un campo nuovo senza versione dichiarata e senza lock prende l'ultima e fallisce
  (oc:7546, oc:8568).
- Un campo nuovo **non aggiunge una voce PSR-4** in `composer.json`: il consumer non la vede finché
  non rifà `composer update`, perché l'autoload legge `vendor/composer/installed.json`, e fino ad
  allora è una classe non trovata a **ogni** pagina di Nova. Le classi vanno nella cartella del
  campo, o in `src/` con `\src` nel namespace come fa `TrackColor` (oc:8568).
- Su una mappa OpenLayers **l'ordine dei layer decide anche cosa è raggiungibile col mouse**, non
  solo cosa si vede: il tooltip nasce da `forEachFeatureAtPixel`, che si ferma alla prima feature
  dall'alto. Un poligono pieno in cima — un settore, un'area — rende muto tutto ciò che sta sotto.
  E due layer che condividono la stessa `source` con `declutter` su uno solo si contendono quella
  ricerca: le etichette vanno su feature clonate e senza `tooltip` (oc:8568).
- Trix scarta silenziosamente un `<iframe>` già salvato alla riapertura, e il save successivo lo
  cancella dal DB: va collassato in un marker testuale prima di passarglielo (oc:8349).
- Verifica sempre il consumer frontend reale prima di considerare chiusa una feature che scrive
  `config.json`: un `box_type` sintatticamente corretto ma sconosciuto a wm-core rende il box
  invisibile in app, senza errori (oc:8241).
