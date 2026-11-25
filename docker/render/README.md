# Dipendenze di compilazione dei PDF di stampa

Questa cartella contiene lo script che installa TeX Live nell'immagine PHP di uno shard, necessario
al compilatore dei PDF (`XelatexCompiler`).

## Uso nel Dockerfile dello shard

Il compose dello shard passa questa cartella come build context aggiuntivo (`additional_contexts`,
ad esempio con nome `wmpackage` verso `wm-package/docker/render`). Nel Dockerfile:

```dockerfile
COPY --from=wmpackage install-deps.sh VERSION /tmp/wm-print/
RUN bash /tmp/wm-print/install-deps.sh
```

Lo script legge `VERSION` dalla propria cartella.

## Cosa fa

- Accetta solo Debian 13 o successivo (base `php:8.4.x-fpm`), altrimenti esce con errore.
- Installa `texlive-xetex`, `texlive-fonts-recommended` e `fontconfig` con `--no-install-recommends`,
  pulisce le liste apt ed esegue `fc-cache -f`. È idempotente.
- Verifica che `xelatex` sia in `/usr/bin/xelatex` (il compilatore usa un PATH fisso).
- Scrive il marcatore `/usr/local/share/wm-print/version` con il contenuto di `VERSION`.

## Marcatore e VERSION

`VERSION` contiene una sola riga (un intero). `XelatexCompiler` confronta il marcatore con
`config('wm-package.print.deps_version')`, che legge lo stesso file: incrementa `VERSION`
quando cambiano le dipendenze, così le immagini vecchie vengono riconosciute.

## Font

I font del template (ad esempio Work Sans) non sono installati da questo script: ogni shard li
installa a parte nel proprio Dockerfile.
