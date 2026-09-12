---
paths:
  - "tests/**"
---

# Trappole: test

Si applica quando scrivi o modifichi un test del package.

- **Il database di test del package è `wm_package`**, dichiarato in `phpunit.xml.dist`. Un
  `phpunit.xml` locale ha la precedenza sul `.dist`: se lo crei senza le righe `DB_*`, la suite
  punta al database dell'`.env` e `RefreshDatabase` lo svuota — è già successo su `forestas`
  (oc:8182).
- **Un test che crea un'App con `native_app_deep_link_enabled = true` scrive sul registro SFTP
  condiviso**: è la regola in cima, non una sfumatura (oc:8251).
- **Un test che non dichiara il `TestCase` giusto fallisce in silenzio**: package e consumer hanno
  due `TestCase` diversi, e i consumer non registrano l'autoload del package. Il dettaglio è in
  [docs/knowledge/testare-il-package.md](docs/knowledge/testare-il-package.md).
- Nelle Action invocate direttamente nei test `request()->user()` è **sempre `null`** (si bypassa
  il kernel HTTP): si usa `auth()->user()`, identico in produzione (oc:8486).
