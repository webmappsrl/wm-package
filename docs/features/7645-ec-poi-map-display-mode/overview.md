> Ticket: oc:7645

# EC POI: scelta icona o immagine sulla mappa

## Cosa cambia

Oggi il frontend decide autonomamente cosa mostrare sulla mappa in base alla sola presenza di un'immagine: se l'EC ha una foto, mostra la foto; altrimenti mostra l'icona categorica. Questo comportamento non è controllabile dall'utente admin.

Con questa feature il backend espone `feature_image.use_image_as_icon: true/false` nel JSON dei related_pois dell'EcTrack. Il frontend legge quel flag e decide cosa mostrare sulla mappa, senza più logica decisionale propria.

## Perché

Cammini d'Italia vuole una mappa visivamente coerente con icone uniformi per tipo di POI, indipendentemente dalla presenza di immagini. La gestione dell'immagine (gallery, scheda dettaglio) deve essere separata dalla scelta di cosa mostrare sull'icona della mappa.

## Requisiti

- [ ] `use_image_as_icon` (boolean, default `false`) salvato in `TaxonomyPoiType.properties->use_image_as_icon` — Nova field (Select/Toggle) + accessor sul modello
- [ ] `use_image_as_icon` (nullable boolean, default `null`) salvato in `EcPoi.properties->use_image_as_icon` — Nova field (Select con "Eredita dalla categoria" → null) + accessor sul modello
- [ ] Nessuna migration — entrambi i modelli hanno già `properties` (jsonb)
- [ ] Backend risolve server-side: `EcPoi.properties->use_image_as_icon` non null → usa quello; altrimenti → `TaxonomyPoiType.properties->use_image_as_icon` della prima categoria POI (ordinata per `id` ASC); nessuna categoria → fallback `false`
- [ ] Il risultato viene scritto come `feature_image.use_image_as_icon: true/false` solo nel JSON dei related_pois dell'EcTrack, tramite un `RelatedEcPoiResource` dedicato
- [ ] L'API standalone di EcPoi non cambia — nessun breaking change

## Rischi

- **Nessuna categoria associata**: fallback assoluto a `false` (usa icona categorica)
- **Categorie multiple**: si usa la prima categoria per `id` ASC — deterministico
- **`feature_image` null**: se l'EC non ha immagini, `use_image_as_icon` viene aggiunto comunque come campo separato dentro `feature_image` — occorre gestire il caso in cui `feature_image` è null nel resource

## Out of scope

- Modifiche al frontend wm-core (gestito separatamente)
- Backfill dati esistenti (tutti gli EC ereditano il default `false` della categoria)
- Preview/anteprima icona nell'admin Nova
- Dimensioni/stili diversi tra icona e immagine nella mappa
- Esposizione di `use_image_as_icon` nell'API standalone EcPoi

## Moduli toccati

**wm-package:**
- `src/Models/Abstracts/Taxonomy.php` — accessor `getUseImageAsIcon()`
- `src/Models/EcPoi.php` — accessor `getUseImageAsIcon()` + metodo `resolveUseImageAsIcon()`
- `src/Nova/TaxonomyPoiType.php` — Nova field per `properties->use_image_as_icon`
- `src/Nova/EcPoi.php` — Nova field per `properties->use_image_as_icon`
- `src/Http/Resources/RelatedEcPoiResource.php` — nuovo resource che estende `EcPoiResource` e aggiunge `feature_image.use_image_as_icon`
- `src/Http/Resources/EcTrackResource.php` — usa `RelatedEcPoiResource` invece di `EcPoiResource` in `getRelatedPois()`
