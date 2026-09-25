# Località mostrate (`taxonomy_where`)

Perché il package salva e mostra le località di tracce, POI e UGC (`properties.taxonomy_where`) nel modo in cui lo fa, e cosa è già stato provato. Il formato, l'opzione "Località mostrate", i punti di uscita filtrati e il comando `wm:resync-taxonomy-where` sono descritti in [docs/resources/TaxonomyWhere.md](../resources/TaxonomyWhere.md), che resta la fonte per il funzionamento.

## Come funziona oggi

- Un solo formato salvato, `{id: {<lingue>, _admin_level, _source}}`; il filtro per categoria si applica **solo** alle uscite pubbliche, mai al dato né a `GeoJsonService::getModelAsGeojson()`.
- Il riallineamento dei dati esistenti è conservativo: scrive solo risultati non vuoti e salva il valore precedente in `_taxonomy_where_backup`.
- I nomi delle where importate da osmfeatures sono nelle 5 lingue della piattaforma, presi da `osm_tags` del dettaglio.

## Perché così

- **Formato vecchio in scrittura** (oc:8588): wm-core (`<wm-txn-where>`) e wp-geohub (`single_track.php`) leggono `taxonomy_where` grezzo e riconoscono solo `_admin_level` con le lingue al primo livello. Col formato di oc:8487 la sezione "Dove" del dettaglio spariva. Con due consumer esterni si è preferito un solo formato salvato a una traduzione in uscita.
- **Filtro in uscita e non sul dato** (oc:8588): i filtri per comune continuano a funzionare e l'opzione si annulla senza ricalcolare nulla. Verificato nel test manuale: svuotando l'opzione tornano regione e comuni.
- **Riallineamento conservativo** (oc:8588): il job per singolo record di oc:8487 azzera il campo prima di chiedere a osmfeatures; con un errore di rete le 1040 tappe senza regione di camminiditalia sarebbero rimaste vuote.
- **Rigenerazione in due fasi** (oc:8588): il json statico di una traccia incorpora i POI collegati, che vanno riallineati prima di rigenerarlo.
- **Nomi delle where in 5 lingue** (oc:8588): con `app.locale = en` e il nome solo in `it`, Nova e ogni `$where->name` mostravano un nome vuoto.

## Come ci siamo arrivati

- **Formato unificato `{name, admin_level, source}`** (oc:8487, superato da oc:8588): rompeva i due lettori esterni.
- **Nome dell'import = id OSM sotto `en`** (fino a oc:8588): `ImportTaxonomyWhere` salvava `$item['name'] ?? $item['id']` in un campo tradotto con `app.locale = en`; nel primo import di prova di camminiditalia 7099 comuni su 7910 avevano `en = "R…"`.
- **Comando di correzione dei nomi in SQL** (scartato in oc:8588): si è preferito correggere import e job e rifare l'import da un DB senza quelle where.
