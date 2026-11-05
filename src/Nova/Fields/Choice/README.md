# choice — la select di opzioni con icone

Componente Nova di `Wm\WmPackage\Nova\Fields\Choice`: una select, singola o multipla, che
disegna l'icona accanto all'etichetta e può nascondersi in base a un altro campo.

Nasce come copia del componente di `IconSelect`, con due aggiunte che lì mancavano:

- **annuncio del cambio di valore** (`emitFieldValueChange`): senza, i campi dichiarati con
  `dependsOn()` non si aggiornano mai, perché Nova ricostruisce i dipendenti solo quando il
  campo sorgente comunica il nuovo valore;
- **segnaposto al posto dell'icona mancante**: dentro uno stesso elenco convivono opzioni con e
  senza icona, e senza segnaposto le righe non stanno in colonna.

## Compilare

Serve `laravel-nova-devtool`, che sta nel repository privato di Nova: le credenziali sono quelle
di `auth.json` nella root dello shard, e Composer le prende da `COMPOSER_AUTH`. Il `composer.json`
qui vincola `laravel/nova` a `5.7.6`: la licenza non copre le release più recenti, e senza vincolo
l'installazione muore con un HTTP 402. Si lancia nel container, che ha PHP 8.4 — sull'host, a 7.4,
`nova-devtool` non è nemmeno installabile.

```bash
cd src/Nova/Fields/Choice
composer install        # porta laravel/nova-devtool in vendor/
npm install
npm run prod            # scrive dist/
```

Due trappole del repo, già applicate qui e da non perdere:

- `externals: { vue: 'Vue' }` nel `webpack.mix.js`, altrimenti il bundle include una seconda
  copia di Vue e il campo non renderizza — nessun errore, il tab appare vuoto (oc:7546);
- `webpack` pinnato **esatto** a `5.103.0`: la 5.111 non compila, perché `laravel-mix@6` cerca
  `webpack/lib/SizeFormatHelpers`, rimosso in mezzo (oc:8568).

## Com'è messo insieme

Tutto il campo sta in questa cartella: `Choice.php`, `FieldServiceProvider.php`, i sorgenti Vue e
il `dist/`, che è versionato come per gli altri campi. Cartella e namespace combaciano
(`Wm\WmPackage\Nova\Fields\Choice`), quindi la regola PSR-4 di base del package basta da sola e
in `composer.json` non c'è nessuna voce dedicata — come per `TrackColor`.

Il provider è registrato in `src/WmPackageServiceProvider.php` e `Choice::$component` vale
`choice`. Lo usa anche `IconSelect`, che estende `Choice` e non dichiara un componente suo: il
vecchio bundle `icon-select` resta compilato ma non è più quello che disegna i campi.
