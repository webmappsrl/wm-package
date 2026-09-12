---
paths:
  - "src/Nova/**"
---

# Trappole: Nova

Si applica quando tocchi Resource, campi, action o card Nova del package.

- Sui campi Media si usa **sempre `->singleMediaRules()`, mai `->rules()`**: quest'ultimo valida
  l'intero array della collection e blocca **ogni** salvataggio del form, anche senza toccare il
  campo (oc:8247).
- `Images::make()` (Ebess) impone `['image']` non sovrascrivibile, e con Laravel 12 quella regola
  esclude gli SVG: per un SVG serve `Files::make()` (oc:8272).
- Nel callback `->store()` di un campo `File`, `return null` **distrugge il valore esistente** a
  ogni update senza file: si torna `true` per non toccarlo. E il callback gira prima del `save()`,
  quindi in creazione `$model->id` è `null` (oc:8175).
- `Multiselect::options()` accetta **un solo argomento**: il secondo è ignorato in silenzio
  (oc:7953).
- Il titolo di una Resource **non può essere una colonna enum** (500 in pagina): serve un metodo
  `title()`. E i modelli devono dichiarare nullable anche le colonne obbligatorie, perché Nova
  costruisce i campi su un'istanza vuota (oc:8489).
- Una classe Tailwind che Nova non usa nella propria UI **viene eliminata dal purge e non ha alcun
  effetto**: si usano solo classi verificate nel CSS di Nova, o CSS scoped (oc:7546).
- `<Teleport to="body">` è obbligatorio per un modale dentro i tab di Nova, altrimenti è
  disallineato (oc:7546).
- Il toast 422 di Nova mostra sempre una stringa fissa: un messaggio di validazione custom non è
  mostrabile senza patchare il vendor (oc:8247).
- I link costruiti a mano in un field usano `Nova::path()`: un path hardcoded perde il prefisso
  `/nova` (oc:8089).
- **Il package registra una sola policy** (`App`). Un consumer che monta la Resource `EcTrack`
  senza registrare `EcTrackPolicy` lascia Nova autorizzare chiunque — verificalo quando aggiungi
  una Resource EC a un consumer (oc:8181).
