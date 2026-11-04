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

Serve `laravel-nova-devtool`, che sta nel repository privato di Nova: occorrono le credenziali.

```bash
cd src/Nova/Fields/ChoiceField
composer install        # porta laravel/nova-devtool in vendor/
npm install
npm run prod            # scrive dist/
```

Due trappole del repo, già applicate qui e da non perdere:

- `externals: { vue: 'Vue' }` nel `webpack.mix.js`, altrimenti il bundle include una seconda
  copia di Vue e il campo non renderizza — nessun errore, il tab appare vuoto (oc:7546);
- `webpack` pinnato **esatto** a `5.103.0`: la 5.111 non compila, perché `laravel-mix@6` cerca
  `webpack/lib/SizeFormatHelpers`, rimosso in mezzo (oc:8568).

## Dopo la prima build riuscita

Finché `dist/` non esiste, `Choice` usa il componente storico `icon-select`, che è già
compilato: così nessuna pagina si rompe, ma le due aggiunte qui sopra non sono attive. A build
fatta servono due passi:

1. in `src/Nova/Fields/Choice.php`, `public $component = 'choice';`
2. in `src/WmPackageServiceProvider.php`, registrare
   `Wm\WmPackage\Nova\Fields\ChoiceFieldServiceProvider`.

Il `dist/` va versionato, come per gli altri campi del package.
