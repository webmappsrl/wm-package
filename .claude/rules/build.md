---
paths:
  - "src/Nova/Fields/**"
  - "src/Nova/Cards/**"
---

# Trappole: build e frontend

Si applica quando tocchi la build di un campo o di una card Nova custom (ogni cartella ha il proprio `webpack.mix.js` e `package.json`).

- `externals: { vue: 'Vue' }` nel `webpack.mix.js`, altrimenti il bundle include una seconda copia
  di Vue e il campo non renderizza: nessun errore, il tab appare vuoto.
- `webpack` pinnato **esatto** a `5.75.0`: le versioni successive rompono `webpack-cli@4`
  bundlato da `laravel-mix@6` (oc:7546).
- Trix scarta silenziosamente un `<iframe>` già salvato alla riapertura, e il save successivo lo
  cancella dal DB: va collassato in un marker testuale prima di passarglielo (oc:8349).
- Verifica sempre il consumer frontend reale prima di considerare chiusa una feature che scrive
  `config.json`: un `box_type` sintatticamente corretto ma sconosciuto a wm-core rende il box
  invisibile in app, senza errori (oc:8241).
