> Ticket: oc:8958

# Nomenclatura regioni su "Filtri"

Il ticket tocca tre repo con lo stesso slug: questo (`wm-package`), `camminiditalia` (da dove
vengono i nomi delle regioni negli attributi dei layer) e `wm-core` nell'app (lingua del filtro).
Qui la parte di `wm-package`: il `name` della Resource Nova `TaxonomyWhere`.

## Cosa cambia

Nella Resource Nova `TaxonomyWhere` il `name` si modifica per lingua, con `NovaTabTranslatable`,
come nelle altre Resource delle tassonomie (`AbstractTaxonomyResource`).

## Perché

Per correggere il nome di una regione (in camminiditalia «Friuli-Venezia Giulia» →
«Friuli Venezia Giulia», «Trentino-Alto Adige» → «Trentino Alto Adige») bisogna poter modificare
il nome italiano. Oggi `TaxonomyWhere::fields()` sovrascrive quelli di `AbstractTaxonomyResource` e
rimette un `Text::make('name')` semplice: su un campo tradotto (Spatie, `Taxonomy::$translatable`)
modifica solo la lingua di `app.locale`, che in camminiditalia è `en`. Da Nova si cambia quindi
l'inglese e non l'italiano.

Il difetto è del package, non di uno shard: la correzione vale per tutti.

## Requisiti

- [ ] `name` in `fields()` dentro `NovaTabTranslatable::make([...])`, un tab per lingua
- [ ] Index e detail continuano a mostrare il nome (`$title = 'name'`)
- [ ] Gli altri campi (`source`, mappa, `Geometry`, `Properties`), filtri e action restano come sono
- [ ] La policy resta invariata: modifica solo per `Administrator`
- [ ] Test che la Resource espone `name` tradotto per lingua

## Rischi

- `NovaTabTranslatable` salva tutte le lingue del tab: un salvataggio con una lingua vuota
  non deve cancellare il valore esistente. Si verifica in Nova sul dev, su una `TaxonomyWhere` con
  i 5 nomi popolati.
- La modifica arriva a tutti gli shard al prossimo aggiornamento del package: è il comportamento
  delle altre tassonomie, quindi nessun cambio di permessi.

## Out of scope

- Cambiare la policy o la visibilità del menu.
- Le action di import e sincronizzazione delle `TaxonomyWhere`.

## Moduli toccati

| File | Repo |
|---|---|
| `src/Nova/TaxonomyWhere.php` | `wm-package` |
| test della Resource (`tests/`) | `wm-package` |
