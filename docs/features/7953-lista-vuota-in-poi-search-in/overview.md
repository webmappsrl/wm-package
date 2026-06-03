> Ticket: oc:7953

# lista vuota in "POI Search In"

## Cosa cambia
Il campo Nova `POI Search In` nel tab "searchable" della risorsa App mostra ora le opzioni selezionabili per configurare i criteri di ricerca dei POI nell'app mobile. In precedenza il multiselect era vuoto e non permetteva alcuna selezione.

## Perché
Il campo `Multiselect::make(__('POI Search In'), 'poi_searchables')` era stato introdotto senza la chiamata a `.options()`, a differenza del campo analogo `Track Search In`. Il campo esisteva nel DB (`poi_searchables` nullable JSON) e veniva consumato correttamente da `EcPoi::getSearchableString()`, ma l'operatore non poteva configurarlo dall'interfaccia Nova.

## Requisiti
- [ ] Il multiselect `POI Search In` mostra le opzioni: Name, Description, Excerpt, OSMID, POI Types
- [ ] Le opzioni corrispondono esattamente ai campi gestiti da `EcPoi::getSearchableString()`
- [ ] I valori già salvati in `poi_searchables` vengono pre-selezionati al caricamento del form (come avviene per `track_searchables`)
- [ ] Il campo include un testo di help che elenca i criteri disponibili
- [ ] Il comportamento di salvataggio e reindex rimane invariato (manuale, identico ai track)

## Rischi
- **Nessun dato preesistente da migrare**: il campo non ha mai funzionato via UI, quindi `poi_searchables` è null per tutti i record esistenti. Il fix non introduce inconsistenze.
- **Allineamento backend/frontend**: le opzioni esposte nel multiselect coincidono esattamente con i campi controllati da `getSearchableString()` — nessun rischio di selezionare opzioni senza effetto.

## Out of scope
- Reindex automatico al salvataggio dell'App (non implementato nemmeno per i track)
- Aggiunta di nuovi campi di ricerca a `getSearchableString()` per i POI
- Modifica al funzionamento di `EcPoi::getSearchableString()`

## Moduli toccati
- `wm-package/src/Nova/App.php` — metodo `searchable_tab()`, campo `POI Search In`
