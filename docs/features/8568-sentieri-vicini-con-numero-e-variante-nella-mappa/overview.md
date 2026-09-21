> Ticket: oc:8568

# Sentieri vicini con numero e variante nella mappa

## Cosa cambia

La mappa composta da `TrailRegistryCode::getFeatureCollectionMap()` disegna, oltre alla traccia
dell'istanza e ai settori attraversati, **gli altri sentieri già presenti nel settore scelto**,
ciascuno con **numero e variante scritti sulla mappa** — per esempio `62`, `62A`.

Il metodo è uno solo e alimenta lo stesso campo Nova `TrailRegistryMap` sia nel dettaglio
dell'istanza sia nel registro dei codici: **la modifica vale per entrambe le viste**, e separarle
sarebbe lavoro in più, non in meno.

## Perché

È ciò che serve all'operatore per giudicare il numero proposto dal sistema: la numerazione segue
una logica di zona, non di disponibilità. Saba in call: «magari il sistema mi ha proposto di dare
il B283, ma io mi rendo conto che a fianco ho il B211, il B212. Allora dico no, lo chiamo B213»;
e poco dopo: «avrei piacere qui di vedere una mappina con il settore in overlay e i sentieri già
presenti […] così mi faccio un'idea completa del settore».

Le varianti vanno mostrate perché l'istanza in esame può essere essa stessa una variante: «magari
il sistema mi ha proposto un numero intero 280, ma questa è una variante del 213, quindi io voglio
mettere 213A».

L'etichetta si ricava dal **registro dei codici**, non dal campo `ref` del sentiero: il `ref`
arriva dall'import di Sardegna Sentieri e può essere illeggibile o discordante dal settore in cui
la geometria ricade davvero — sono due dei tipi di anomalia che il comando di normalizzazione
rileva. Etichettare col `ref` grezzo mostrerebbe all'operatore proprio il dato sbagliato che gli
serve corretto per decidere.

Di quel codice si mostrano solo numero e variante: regione, provincia e area sono costanti nel
contesto, e il settore è già leggibile sulla mappa, dove i suoi confini sono disegnati. Piccioli in
call: «tutto il resto del settore è definito, sai in che settore sei […] è anche molto più
leggibile».

## Requisiti

- [ ] La mappa disegna le geometrie dei sentieri del **settore scelto**, presi dal registro dei
      codici (`trail_registry_codes` del settore), non da una query spaziale su tutti gli `EcTrack`
- [ ] L'etichetta non concatena i campi grezzi: nel database «senza variante» si scrive `'0'`, e
      attaccando `number` e `variant` uscirebbe `620` invece di `62` — un numero sbagliato su uno
      strumento che serve a scegliere i numeri, e abbastanza plausibile da non farsi notare. La
      regola sta oggi in un solo punto (`TrailRegistryCode.php:86`, `$this->variant === '0' ? '' :
      $this->variant`) e deve restare in uno solo: si estrae in un accessor breve che usano sia il
      codice completo sia l'etichetta
- [ ] Il codice in esame **non compare fra i suoi vicini**: la sua traccia è già l'elemento
      principale della mappa, e l'elenco «tutti i codici del settore» lo riporterebbe dentro,
      disegnandolo due volte sopra sé stesso con due tooltip in conflitto
- [ ] Il recupero sta in **un metodo solo**, che riceve il settore e torna in **una query sola**
      i codici attivi con geometria, numero e variante già pronti — non una `ST_AsGeoJSON` per
      riga come fa oggi `geojsonFrom()`, che con 62 vicini diventerebbero altrettanti giri al
      database prima che la mappa compaia. Si parte dal `full_code` a registro e non da
      un'intersezione spaziale, perché il settore scritto nel codice **è già** il risultato di
      quell'intersezione, calcolato quando il codice è nato: rifarla a ogni apertura della mappa
      sarebbe ricalcolare una cosa già decisa
- [ ] La geometria di un codice viene dall'`EcTrack` quando c'è, **altrimenti dall'istanza**
      (`application`); se il codice ha entrambi, vince il sentiero. Così nessun codice attivo
      sparisce dalla mappa: `ecTrack()` è nullable, e un codice `Reserved` nato da un'istanza non
      ancora approvata non ha sentiero — sul DB locale sono 2 codici su 594, tutti `reserved`. Sono
      i più recenti, quindi i più rilevanti per chi sceglie un numero, e un numero dato su una
      mappa che non li mostra produce un conflitto che nessun revert annulla
- [ ] Entrano solo i codici negli stati che **occupano una posizione** — `Reserved` e `Assigned`,
      cioè `TrailCodeStatus::active()` — esattamente la logica che popola la select del
      «sostituisci numero»; i `Released` sono esclusi perché non occupano più il numero
- [ ] Numero e variante sono **scritti sulla mappa**, non affidati al solo tooltip: confrontare
      211, 212 e 283 passando il mouse su una traccia per volta è il lavoro che questa feature deve
      togliere
- [ ] [UX] Le etichette non si accavallano: il layer del componente nuovo attiva `declutter`, così
      in un settore denso quelle che non trovano posto spariscono invece di sovrapporsi e rendere
      illeggibili anche le altre
- [ ] [UX] L'etichetta è disegnata **lungo il tracciato** (`placement: 'line'`), non nel punto
      medio della geometria, che per un sentiero a U o spezzato cadrebbe fuori dal sentiero. Da
      verificare il rovescio: con questo posizionamento una traccia più corta del testo resta senza
      etichetta, e il numero va comunque preso dal tooltip
- [ ] [UX] Le tracce vicine sono graficamente distinte da quelle dell'istanza in esame — tratto
      sottile e tenue — che restano gli elementi più leggibili della mappa
- [ ] [UX] La legenda (`MapLegendRenderer`) guadagna la voce corrispondente nello stesso commit:
      una mappa con un colore che nessuna legenda spiega è un difetto che nessun test coglie. La
      voce è **condizionale come le altre** — compare solo quando almeno un vicino è disegnato,
      seguendo lo schema di `isPresent()` già in uso
- [ ] Numero e variante sono **sempre** leggibili nel tooltip al passaggio del mouse, a qualsiasi
      zoom: è il comportamento che il campo ha già oggi, non va costruito nulla
- [ ] [UX] Le etichette **scritte sulla mappa** compaiono invece da un **livello di zoom in poi**,
      con la soglia **parametrica** e non cablata: sotto quel livello le tracce restano disegnate e
      il numero resta raggiungibile col tooltip, sopra si legge tutto a mappa ferma
- [ ] La soglia è espressa in **livello di zoom**, lo stesso numero che si legge sulla mappa, e
      convertita in `resolution` dentro il componente: la funzione di stile riceve la risoluzione,
      non lo zoom, e una prop che dicesse «zoom» confrontando risoluzioni ingannerebbe chi la tara
- [ ] La soglia è una **prop del nuovo componente**, tarata su ZNUB4. Nessuna voce in
      `config/wm-package.php`: è una proprietà di questa mappa, non del progetto; la config si
      aggiunge il giorno in cui un consumer chiede di cambiarla senza toccare codice
- [ ] `TrailRegistryMap` **dichiara un componente proprio**, che estende `FeatureCollectionMap`
      sullo schema già usato da `Osm2cai\SignageMap\SignageMap` in osm2cai2: bundle a parte,
      cartella con il proprio `webpack.mix.js`, `package.json` e `postcss.config.js`. Il componente
      condiviso **non si tocca**, e con lui restano intatte le mappe di `TaxonomyWhere`, `Layer`,
      `FeatureCollection` e `TrailRegistryAnomaly`
- [ ] Le tracce vicine **non entrano nel calcolo dell'extent** iniziale: la mappa continua ad
      aprirsi inquadrata sul sentiero in esame e sull'istanza, non sull'intero settore

## Rischi

- **[UX] Il rumore in un settore affollato.** Bonfanti in call: «se ne hai 200 poi ti fa rumore»,
  e proponeva di legare le etichette allo zoom; Saba voleva vederle tutte. Le due cose stanno
  insieme perché il numero non si perde mai: sotto la soglia resta nel tooltip, sopra è scritto in
  chiaro. Il rischio residuo è **tarare male la soglia** — troppo alta e a mappa aperta non si
  legge niente, vanificando la feature; troppo bassa e torna il rumore. Per questo è parametrica:
  si corregge cambiando un valore, non riaprendo un ciclo di sviluppo. Il valore iniziale va scelto
  guardando ZNUB4, non a occhio.
- **Peso della mappa.** Sul DB locale il settore più popolato è ZNUB4 con 62 codici a registro
  (poi ZNUG2 con 48, ZSUD3 con 45): la misura va fatta lì, non su un settore di prova.
- **La modifica si vede anche nel registro dei codici.** Il metodo è condiviso, quindi la vista del
  registro cambia aspetto pur non essendo l'oggetto della richiesta. È un effetto accettato, non
  una svista: va verificato a mano che lì la mappa resti leggibile.
- **Il `dist` del campo Nova è versionato.** Il bundle del nuovo componente va compilato con
  `npm run prod` **dentro la sua cartella**, mai dalla root, e il diff va controllato perché non
  contenga prop spurie. È la regola del repo sui campi Nova custom: se salta, la mappa in
  produzione resta quella vecchia senza che nulla lo segnali.
- **Un secondo bundle da tenere allineato.** È il costo che il docblock di `TrailRegistryMap`
  elencava per non avere un componente proprio: un fix al componente condiviso non arriva più qui
  da solo. Si accetta perché il disegno di questa mappa ha smesso di essere «quello di sempre», e
  perché in cambio nessuna delle altre mappe del package rischia una regressione.
- **L'extent iniziale.** Nel componente condiviso l'inquadratura si calcola su **tutte** le
  LineString (`FeatureCollectionMap.vue:358`). Ereditandolo così, 62 tracce vicine aprirebbero la
  mappa su tutto il settore, con la traccia in esame ridotta a un filo e lo zoom iniziale sotto la
  soglia: le etichette non comparirebbero mai e la feature si annullerebbe da sola. Il componente
  nuovo deve escludere i vicini da quel calcolo.

## Out of scope

- I sentieri **privi di codice** nel registro: nel registro dei codici ogni sentiero ha per
  definizione un codice, e mostrare le tracce che non ne hanno è uno scope diverso
- I sentieri degli **altri settori attraversati**: il numero si assegna in un settore solo, ed è lì
  che deve essere coerente coi vicini. Per ora solo il settore scelto
- L'algoritmo che propone il numero per **prossimità** invece del primo libero: oggi
  `TrailRegistryService::propose()` prende il primo numero libero da 0 a 99 nel settore, senza
  alcuna nozione di vicinanza
- La **sostituzione del numero** dall'istanza, con la variante: è il ticket complementare (oc:8569)
- La correzione delle **anomalie** del registro, che ha il suo comando di normalizzazione
- Il livello dei **confini comunali**, chiesto in call come aggiunta separata

## Moduli toccati

Tutto in `wm-package`: la mappa è del package e la vista serve a chiunque monti il Catasto
Sentieri, non solo a Forestas. Nessun file del repo `forestas`.

| File | Cosa cambia |
|---|---|
| `src/TrailRegistry/Models/Concerns/ComposesTrailRegistryMap.php` | Nuovo metodo che recupera i codici attivi del settore scelto con le geometrie dei rispettivi `EcTrack`, e la feature GeoJSON che li disegna con la proprietà di etichetta |
| `src/TrailRegistry/Models/TrailRegistryCode.php` | `getFeatureCollectionMap()` accoglie il nuovo gruppo di feature |
| `src/TrailRegistry/Nova/MapLegendRenderer.php` | La voce di legenda per i sentieri vicini |
| `src/TrailRegistry/Nova/Fields/TrailRegistryMap.php` | Dichiara il componente proprio; il docblock che spiegava perché non ne aveva uno va riscritto |
| Nuovo componente del registro (cartella propria, sullo schema di `SignageMap`) | Estende `FeatureCollectionMap`: label con `Text` sulle linee, soglia di zoom, esclusione dei vicini dall'extent. Porta il proprio `webpack.mix.js`, `package.json` e `postcss.config.js` |
| `dist/` del nuovo componente | Bundle compilato con `npm run prod` dentro la sua cartella, mai dalla root |
| `tests/Feature/TrailRegistry/TrailRegistryCodeMapTest.php` | Un settore con altri sentieri produce le loro geometrie e le etichette attese; i codici `Released` non compaiono |
