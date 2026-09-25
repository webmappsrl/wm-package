> Ticket: oc:8637

# Nome utente sul marker live attivabile per shard — piano di implementazione (wm-package)

> **Per chi esegue:** sotto-skill consigliata `superpowers:executing-plans`. I passi usano le
> checkbox (`- [ ]`). **Nessun `git add`/`git commit`/`git push`/branch durante l'esecuzione**: i
> passi "Commit" sono istruzioni testuali per il dev, eseguite solo dopo il review-gate.

**Obiettivo:** sostituire la variabile fissa `$showLiveUserIdentity = false` in
`Layer::getFeatureCollectionMap()` con la chiave `wm-package.analytics_show_live_user_identity`, letta da
`ANALYTICS_SHOW_LIVE_USER_IDENTITY` con default `false`.

**Architettura:** una chiave di config del package, letta nel punto in cui oggi c'è la variabile
fissa. La logica esistente (lookup utenti, tooltip, link) resta com'è e dipende già da
`$showLiveUserIdentity`: il comportamento con il flag acceso torna quello precedente a oc:8586.

**Stack:** Laravel, PHPUnit via Pest, PostgreSQL/PostGIS, PostHog (HTTP simulato con `Http::fake`).

**Spec:** [overview.md](overview.md); parte consumer in
`camminiditalia/docs/features/8637-nome-utente-sul-marker-live-della-mappa-attivabile-per-singolo-shard/`.

## Vincoli globali
- Nome chiave: `analytics_show_live_user_identity`; variabile d'ambiente: `ANALYTICS_SHOW_LIVE_USER_IDENTITY`; default `false`
- Un solo flag controlla insieme nome e link; nessuna distinzione per ruolo
- Testo del tooltip anonimo invariato: `Posizione utente (ultimi 30 minuti)`
- Link invariato: `url('nova/resources/users/'.$user->id)`
- Commenti e documentazione in italiano
- Test del package nel container `php-forestas`, da `wm-package/` (regole del package). Se il
  container non è attivo, avviarlo è una scelta del dev: chiedere prima

## Punti da guardare in review
1. Utente esistente con nome e cognome vuoti, flag acceso → tooltip generico **con** link (decisione del dev): test in Task 1
2. `user_id` senza utente corrispondente, flag acceso → tooltip generico, nessun link: test in Task 1
3. Nessuna config impostata → marker anonimo anche con utente risolvibile (default reale): test esistente, rinominato in Task 1
4. `ANALYTICS_SHOW_LIVE_USER_IDENTITY=false` scritto come stringa nel `.env` → `env()` lo converte in booleano `false`: comportamento standard Laravel, nessun test (legge l'ambiente di processo)
5. Più posizioni nella stessa risposta con utenti diversi → ogni marker ha il proprio nome e link: test in Task 1

---

### Task 1: flag in config, lettura in `Layer`, test dei due stati

> ⚠️ L'implementazione ha deviato da questo task: [notes.md](notes.md#task-1-flag-in-config-lettura-in-layer-test-dei-due-stati)

**File:**
- Modifica: `config/wm-package.php:14` (dopo `layer_user_presence_distance_meters`)
- Modifica: `src/Models/Layer.php:499-506` (commento e assegnazione del flag)
- Modifica: `src/Services/PostHog/AnalyticsService.php:452-458` (solo docblock)
- Test: `tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php`

**Interfacce:**
- Produce: `config('wm-package.analytics_show_live_user_identity')` — `bool`, default `false`

- [ ] **Passo 1: scrivere i test con il flag acceso (devono fallire)**

In fondo alla classe `LayerFeatureCollectionMapUserPresenceTest` aggiungere un helper e quattro test:

```php
    /**
     * Estrae i soli marker di posizione utente (riconoscibili da checkpointRouteColors).
     *
     * @return list<array<string, mixed>>
     */
    private function userPositionFeatures(Layer $layer): array
    {
        return array_values(array_filter(
            $layer->getFeatureCollectionMap()['features'],
            fn ($f) => isset($f['properties']['checkpointRouteColors'])
        ));
    }

    /**
     * oc:8637: con il flag acceso il marker torna a mostrare nome e cognome e il link alla
     * scheda Nova dell'utente, come prima di oc:8586.
     */
    public function test_flag_enabled_shows_full_name_and_link_when_user_is_resolvable(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $user = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame('Maria Rossi', $features[0]['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$user->id), $features[0]['properties']['link']);
    }

    /**
     * oc:8637: utente esistente senza nome e cognome — tooltip generico ma link presente: se
     * l'utente esiste il link c'è (decisione del dev, comportamento precedente a oc:8586).
     */
    public function test_flag_enabled_keeps_link_with_default_label_when_user_has_blank_name(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $user = User::factory()->create(['name' => '', 'surname' => null]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $user->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $features[0]['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$user->id), $features[0]['properties']['link']);
    }

    /**
     * oc:8637: flag acceso ma user_id senza utente corrispondente (es. utente cancellato) —
     * tooltip generico e nessun link.
     */
    public function test_flag_enabled_falls_back_to_default_label_without_link_when_user_is_missing(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, 999999],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(1, $features);
        $this->assertSame('Posizione utente (ultimi 30 minuti)', $features[0]['properties']['tooltip']);
        $this->assertArrayNotHasKey('link', $features[0]['properties']);
    }

    /**
     * oc:8637: più posizioni nella stessa risposta — ogni marker porta il proprio nome e link,
     * nessuna mescolanza fra utenti.
     */
    public function test_flag_enabled_assigns_each_position_its_own_user(): void
    {
        config(['wm-package.analytics_show_live_user_identity' => true]);
        $maria = User::factory()->create(['name' => 'Maria', 'surname' => 'Rossi']);
        $luca = User::factory()->create(['name' => 'Luca', 'surname' => 'Bianchi']);

        Http::fake([
            '*' => Http::response(['results' => [
                ['near-1', 43.70004, 10.405, $maria->id],
                ['near-2', 43.70003, 10.407, $luca->id],
            ]]),
        ]);

        $features = $this->userPositionFeatures($this->createLayerWithTrack());

        $this->assertCount(2, $features);
        $byTooltip = collect($features)->keyBy(fn ($f) => $f['properties']['tooltip']);
        $this->assertSame(url('nova/resources/users/'.$maria->id), $byTooltip['Maria Rossi']['properties']['link']);
        $this->assertSame(url('nova/resources/users/'.$luca->id), $byTooltip['Luca Bianchi']['properties']['link']);
    }
```

- [ ] **Passo 2: eseguire i test e verificare che falliscano**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php"
```

Atteso: i 4 nuovi test FALLISCONO (tooltip generico e nessun `link`, perché il flag è ancora fisso
a `false`); i test esistenti PASSANO. Se il percorso del package nel container è diverso, ricavarlo
con `docker exec php-forestas pwd` prima di eseguire.

- [ ] **Passo 3: aggiungere la chiave di config**

In `config/wm-package.php`, subito dopo la riga `layer_user_presence_distance_meters`:

```php
    // oc:8637: mostra nome, cognome e link alla scheda Nova dell'utente sul marker live della mappa
    // del layer. Default false (marker anonimo, come da oc:8586): ogni shard lo accende dal proprio
    // .env. Con il flag acceso l'identità è visibile a ogni utente che supera il gate Nova del
    // consumer, su qualunque layer (l'endpoint della mappa non ha autorizzazione per singolo layer).
    'analytics_show_live_user_identity' => env('ANALYTICS_SHOW_LIVE_USER_IDENTITY', false),
```

- [ ] **Passo 4: leggere il flag da config in `Layer::getFeatureCollectionMap()`**

In `src/Models/Layer.php` sostituire il blocco di commento e l'assegnazione (righe 499-506):

```php
        // oc:8586: nominativo e link sono disabilitati per privacy — misura cautelativa richiesta
        // ...
        $showLiveUserIdentity = false;
```

con:

```php
        // oc:8637: nominativo e link sul marker live dipendono da una config per shard
        // (wm-package.analytics_show_live_user_identity ← ANALYTICS_SHOW_LIVE_USER_IDENTITY, default false). Supera la
        // scelta di oc:8586, che li aveva fissati a false nel codice: allo scrum del 23/09/2026 si è
        // deciso di renderli opzionali per shard, senza interruttore in Nova.
        // Limite noto: l'identità mostrata è quella dichiarata dall'app nell'evento PostHog
        // `userMoved` (properties.user_id), non verificata dal backend.
        $showLiveUserIdentity = (bool) config('wm-package.analytics_show_live_user_identity', false);
```

Il resto del metodo non cambia.

- [ ] **Passo 5: aggiornare il docblock di `AnalyticsService::getRecentUserPositions()`**

In `src/Services/PostHog/AnalyticsService.php` sostituire le righe:

```php
     * (quest'ultimo è solo una chiave di join interna, scartata prima del return) — dopo oc:8586
     * il chiamante (Layer::getFeatureCollectionMap()) lo legge solo se `$showLiveUserIdentity` è
     * `true` (hardcoded a `false` per privacy, in attesa di parere legale): il marker live è oggi
     * sempre anonimo, il campo resta nel payload per la riattivazione futura di quel flag.
```

con:

```php
     * (quest'ultimo è solo una chiave di join interna, scartata prima del return) — il chiamante
     * (Layer::getFeatureCollectionMap()) lo usa solo se la config
     * `wm-package.analytics_show_live_user_identity` è attiva (default false, oc:8637). Il valore arriva
     * dall'evento inviato dall'app e non è verificato dal backend.
```

- [ ] **Passo 6: aggiornare i docblock dei tre test esistenti con il flag spento**

I tre test esistenti non impostano la config, quindi verificano già il **default reale**
(collegamento file di config → `Layer.php`). Rinominarli e riscriverne il docblock perché lo dicano:

- `test_position_shows_anonymous_label_and_no_link_even_when_user_id_is_resolvable` →
  `test_default_config_keeps_marker_anonymous_even_when_user_is_resolvable`, docblock:

  ```php
      /**
       * oc:8637: senza impostare la config, il default del package (analytics_show_live_user_identity =
       * false) tiene il marker anonimo e senza link anche con un utente risolvibile. Non impostare
       * la config in questo test: è il controllo che il default resti false.
       */
  ```

- `test_position_falls_back_to_default_label_when_user_id_has_no_matching_user` → nome invariato,
  docblock: `oc:8637: con il default (flag spento) un user_id senza utente corrispondente dà tooltip generico e nessun link.`
- `test_position_shows_anonymous_label_and_no_link_when_user_has_blank_name` →
  `test_default_config_keeps_marker_anonymous_when_user_has_blank_name`, docblock:
  `oc:8637: con il default (flag spento) anche un utente con nome vuoto dà tooltip generico e nessun link; con il flag acceso il link c'è (vedi test_flag_enabled_keeps_link_with_default_label_when_user_has_blank_name).`

Verificare che `setUp()` **non** imposti `wm-package.analytics_show_live_user_identity`.

- [ ] **Passo 7: eseguire la suite del file e verificare che passi**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pest tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php"
```

Atteso: tutti i test PASSANO (2 esistenti rinominati + 1 con solo il docblock nuovo + 3 invariati + 4 nuovi).

- [ ] **Passo 8: Pint solo sui file toccati e PHPStan**

```bash
docker exec -it php-forestas bash -c "cd wm-package && vendor/bin/pint config/wm-package.php src/Models/Layer.php src/Services/PostHog/AnalyticsService.php tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php && composer analyse"
```

Atteso: nessun errore PHPStan sui file toccati. Poi `git status`: solo i quattro file del task più
i documenti in `docs/features/8637-.../`.

- [ ] **Passo 9: commit (istruzione per il dev, dopo il review-gate)**

```bash
git add config/wm-package.php src/Models/Layer.php src/Services/PostHog/AnalyticsService.php \
  tests/Feature/LayerFeatureCollectionMapUserPresenceTest.php \
  docs/features/8637-nome-utente-sul-marker-live-della-mappa-attivabile-per-singolo-shard/
git commit -m "feat(oc:8637): nome utente sul marker live attivabile per shard via ANALYTICS_SHOW_LIVE_USER_IDENTITY"
```

PR verso `develop` di wm-package. Da mergiare **prima** della parte consumer (regola del package).
