# Messa in sicurezza PHP di uno shard (PHP2Shell e casi analoghi)

> Nata con oc:8833 (PHP2Shell, CVE-2026-109618/109619, ottobre 2026). La sequenza vale per
> qualunque falla del motore PHP: è scritta per essere rieseguita alla prossima CVE.
>
> **⚠️ I valori numerici e i percorsi citati sono l'esempio di forestas (oc:8833), non la
> regola**: i limiti di upload si misurano sui dati del proprio shard, le versioni PHP dipendono
> dalla CVE del momento, e il Dockerfile sorgente va individuato shard per shard. Ciò che è
> prescrittivo è la sequenza, non i numeri.

Versioni minime per PHP2Shell: **8.4.27** (FPM degli shard), **8.3.36** (WordPress),
8.2.35 / 8.5.12 per gli altri rami. La falla sta nel motore: lo shard è esposto anche se il suo
codice è perfetto.

## Ordine vincolante: fotografa → raccogli → patcha

Il rebuild dei container ne azzera il filesystem: ogni traccia di un'eventuale intrusione
(webshell, cron, processi) sparisce con l'aggiornamento stesso. Per questo l'ordine non si
inverte mai:

1. **Snapshot del disco dal pannello del provider** (Hetzner: tab Snapshots della macchina).
   Forma migliore: power off → snapshot → power on; a caldo va comunque bene per lo scopo
   forense. Lo snapshot **non** è uno stato «pulito» a cui tornare: contiene anche l'eventuale
   compromissione. È evidenza + paracadute operativo.
2. **Se lo snapshot non è possibile** (provider senza la funzione, VM non nostra), si fotografa a
   mano, nell'ordine: `docker commit` di ogni container PHP/WordPress; tar dei log del web server
   dell'host (con le rotazioni che coprono la finestra di esposizione); dump dei DB; copia di
   tutto **fuori dalla macchina** (scp) prima di toccare qualsiasi cosa.
3. **Raccolta evidenze** (sola lettura): log di accesso/errore dell'host; per ogni container
   PHP/WordPress: `ps aux`, file modificati di recente nelle directory scrivibili, crontab,
   `docker logs`. Le evidenze si salvano **fuori dai repo** (sono pubblici).
4. **Solo ora: patch e rebuild.**

## Individuare il Dockerfile sorgente dell'immagine

Il tag `wm-phpfpm:*` può essere prodotto da **più Dockerfile diversi**: ogni shard ha il suo in
`docker/configs/phpfpm/` (è quello che i compose dello shard buildano), e anche questo package ne
ha uno (`docker/configs/phpfpm/Dockerfile`, base mobile `php:8.4-fpm`, usato per lo sviluppo del
package; il compose del package usa inoltre un tag suo, `wm-phpfpm:8.2.15-fpm`). Prima di bumpare:

1. trova quale compose/`build:` produce l'immagine che i container dello shard usano davvero;
2. verifica che il profilo del container in esercizio corrisponda a quel Dockerfile
   (`docker exec <php> php -m` e confronto con le estensioni installate dal Dockerfile);
3. il bump si fa **in quel** Dockerfile. Se il profilo non corrisponde a nessun Dockerfile del
   repo, fermati e scopri da dove viene l'immagine prima di sovrascriverla.

Debito noto: i Dockerfile degli shard e del package andrebbero unificati in un'immagine base
condivisa — finché non succede, questo controllo va rifatto a ogni shard.

## Il bump

1. **Pinna la versione esatta** nel `FROM` (`php:8.4.27-fpm`, `php:8.3.36-apache`): l'aggiornamento
   diventa un commit tracciato; un tag mobile rende l'aggiornamento un evento invisibile.
2. **Conserva l'immagine corrente** prima del rebuild: `docker tag <immagine> <immagine>-pre-<ticket>`.
3. **Rebuild sempre con `--pull`**: con la base in cache, una build senza `--pull` riusa i layer
   vecchi e produce un'immagine identica a quella vulnerabile dichiarando «build riuscita». È la
   trappola principale di tutta la procedura.
4. **Riavvia tutti i container** che usano l'immagine (FPM, worker Horizon, init).
5. **La verifica è sempre `php -v` dentro il container** (e per i valori di configurazione
   `php-fpm -tt`), mai l'esito della build.

## Pulizia di configurazione (riduzione permanente della superficie)

Nel pool FPM (`www.conf`), via `php_admin_*` così l'applicazione non può sovrascriverli:

- `php_admin_flag[session.upload_progress.enabled] = off` — feature quasi mai usata, è stata il
  vettore di una delle due CVE di PHP2Shell;
- `upload_max_filesize` / `post_max_size` commisurati ai flussi reali dello shard: **misurare il
  file più grosso in storage/MinIO del proprio shard prima di scegliere** — su forestas
  (oc:8833) la misura ha dato un massimo di 16,7MB e i limiti sono stati fissati a 32M/64M, ma
  sono i valori di quello shard, non un default da copiare;
- `memory_limit` resta finito (la mitigazione d'emergenza `-1` vale solo in attesa di patch, da
  rimuovere a patch fatta).

## Rotazione precauzionale dei segreti

Se la finestra di esposizione c'è stata (falla pubblica prima della patch), un esito «pulito»
dei log non è una prova: i log sono alterabili da chi ha RCE. Si ruotano quindi i segreti
economici da ruotare — token di client programmatici (es. client SUS), password DB — a
prescindere dall'esito. **Mai** segreti la cui rotazione fa danni estesi (es. `JWT_SECRET`, che
invalida i token di tutti gli utenti) senza evidenza concreta di compromissione.

## Chiusura

- Esito della verifica di compromissione nelle note del ticket (sintetico, senza dati sensibili).
- Un ticket per shard, agganciato al tag Orchestrator `php2shell` (id 706), dove la strategia è
  descritta per intero.
