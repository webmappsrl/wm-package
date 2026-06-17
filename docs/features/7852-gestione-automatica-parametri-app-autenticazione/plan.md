> Ticket: oc:7852

# Plan — Gestione automatica dei parametri di App dipendenti da autenticazione

## Step 1 — Verifica empirica `dependsOn` in tab annidati (PRIMA di toccare il codice)

**Obiettivo:** confermare che `dependsOn` su un `Boolean` funzioni correttamente quando entrambi i campi si trovano dentro `mobile_tab()` → `Tab::group` in Nova 5.

**Come verificare:**

Aggiungere temporaneamente un `dependsOn` di prova su `geolocation_record_enable` in `mobile_tab()`:

```php
Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
    ->default(false)
    ->hideFromIndex()
    ->help(__('Enables user geolocation recording on tracks'))
    ->dependsOn('auth_show_at_startup', function (Boolean $field, NovaRequest $request, FormData $formData) {
        if (! $formData->boolean('auth_show_at_startup')) {
            $field->readonly(true);
        }
    }),
```

Aprire Nova → edit di un'App → tab Mobile → verificare:
- `auth_show_at_startup = false` → `geolocation_record_enable` appare grayed out ✓
- Abilitare `auth_show_at_startup` → `geolocation_record_enable` diventa editabile ✓

**Se il test fallisce** (il campo non risponde): alternativa è un custom Vue component — aprire discussione con il team prima di procedere.

**Se il test passa:** procedere con Step 2 (questo codice temporaneo verrà rimosso e sostituito dall'helper).

---

## Step 2 — Aggiungere import `FormData` in `src/Nova/App.php`

File: `src/Nova/App.php`

Aggiungere dopo la riga `use Laravel\Nova\Http\Requests\NovaRequest;`:

```php
use Laravel\Nova\Fields\FormData;
```

---

## Step 3 — Aggiungere metodo `mobileAuthDependent()` in `src/Nova/App.php`

File: `src/Nova/App.php`

Aggiungere come metodo privato nella classe `App` (posizionarlo dopo `webapp_tab()` e prima di `mobile_tab()`):

```php
private function mobileAuthDependent(Boolean $field): Boolean
{
    return $field->dependsOn('auth_show_at_startup', function (Boolean $field, NovaRequest $request, FormData $formData) {
        if (! $formData->boolean('auth_show_at_startup')) {
            $field->readonly(true);
        }
    });
}
```

---

## Step 4 — Wrappare `geolocation_record_enable` in `mobile_tab()`

File: `src/Nova/App.php`, metodo `mobile_tab()`

Sostituire:

```php
Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
    ->default(false)
    ->hideFromIndex()
    ->help(__('Enables user geolocation recording on tracks')),
```

Con:

```php
$this->mobileAuthDependent(
    Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
        ->default(false)
        ->hideFromIndex()
        ->help(__('Enables user geolocation recording on tracks'))
),
```

---

## Step 5 — Verifica visiva finale in Nova

Aprire Nova → edit di un'App reale → tab Mobile:

- [ ] Con `auth_show_at_startup = false`: `geolocation_record_enable` è grayed out e non cliccabile
- [ ] Con `auth_show_at_startup = true`: `geolocation_record_enable` è editabile normalmente
- [ ] Salvare con `auth_show_at_startup = false`: il valore di `geolocation_record_enable` nel DB non cambia
- [ ] Tab index e detail view: nessuna differenza rispetto a prima

---

## Step 6 — Commit

```
feat(oc:7852): add mobileAuthDependent helper to disable geolocation field when auth is off
```

File da includere nel commit:
- `src/Nova/App.php`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/overview.md`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/plan.md`
- `docs/features/7852-gestione-automatica-parametri-app-autenticazione/notes.md`
