> Ticket: oc:8951

# Piano

Lavoro di due file, eseguito insieme al consumer che lo ha richiesto (camminiditalia); il piano
completo del ciclo sta in quel repo, `docs/features/8951-allineamento-wm-package-1-6-1/plan.md`.

- [x] Dichiarare `poi_types` in `legacy_taxonomies`, con `relation: taxonomyPoiTypes` e
      `model: TaxonomyPoiType`, tab «General».
- [x] Aggiungere `"Poi Types"` alle cinque lingue del package, una riga per file.
- [x] Verificare sul consumer che la voce compaia sul POI e non sulla tappa.
- [x] Verificare che la scrittura funzioni: togliendo un valore dal campo, la riga sparisce dal
      pivot; un salvataggio senza modifiche non tocca le associazioni.
- [x] Suite del package.
