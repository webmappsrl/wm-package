> Ticket: oc:7852

# Plan — Gestione automatica dei parametri di App dipendenti da autenticazione

> **Stato finale:** `mobileAuthDependent()` mantenuto per l'edit; aggiunto HTML field per la detail view. La traduzione lockMessage rimane in `it.json`.

## Step 1 — Aggiungere HTML field per detail view in `mobile_tab()`

File: `src/Nova/App.php`, metodo `mobile_tab()`.

Aggiungere prima del `$this->mobileAuthDependent(...)` esistente:

```php
Text::make(__('Geolocation Record Enable'), 'geolocation_record_enable', function () {
    $effective = $this->auth_show_at_startup && $this->geolocation_record_enable;

    return $effective
        ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-slot="icon" class="w-6 h-6 text-green-500"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-slot="icon" class="w-6 h-6 text-red-500"><path fill-rule="evenodd" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm-1.72 6.97a.75.75 0 1 0-1.06 1.06L10.94 12l-1.72 1.72a.75.75 0 1 0 1.06 1.06L12 13.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L13.06 12l1.72-1.72a.75.75 0 1 0-1.06-1.06L12 10.94l-1.72-1.72Z" clip-rule="evenodd"/></svg>';
})->asHtml()->onlyOnDetail(),
```

SVG da `@heroicons/vue@2.2.0` `24/solid` — stessa libreria e dimensione (`w-6 h-6`, `viewBox="0 0 24 24"`) del `Boolean` nativo di Nova.

---

## Step 2 — Cambiare `hideFromIndex()` in `onlyOnForms()` sul Boolean esistente

File: `src/Nova/App.php`, metodo `mobile_tab()`.

Sul Boolean già wrappato in `mobileAuthDependent()`, cambiare:

```php
->hideFromIndex()
```

in:

```php
->onlyOnForms()
```

**Motivazione:** il Boolean con `hideFromIndex()` apparirebbe anche in detail view, causando duplicazione con il nuovo HTML field. `onlyOnForms()` limita il Boolean a edit/create, dove `mobileAuthDependent()` continua ad operare normalmente.

---

## Step 3 — Verifica manuale in browser

Aprire Nova → detail di un'App reale → tab Mobile:

- [ ] Con `auth_show_at_startup = false`: icona rossa per `geolocation_record_enable`
- [ ] Con `auth_show_at_startup = true` e `geolocation_record_enable = true`: icona verde
- [ ] Con `auth_show_at_startup = true` e `geolocation_record_enable = false`: icona rossa
- [ ] Dimensione icona uguale a quella dei Boolean nativi (es. `auth_show_at_startup`)
- [ ] In edit: `geolocation_record_enable` appare grayed-out quando `auth_show_at_startup = false` (mobileAuthDependent attivo)
- [ ] Salvare con qualsiasi combinazione: il valore DB non cambia per effetto di questa logica

---

## Step 4 — Commit

```
feat(oc:7852): add computed HTML field for geolocation display in detail view
```

File da includere nel commit:
- `src/Nova/App.php`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/overview.md`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/plan.md`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/notes.md`
- `CLAUDE.md`
