> Ticket: oc:8348

# Spostare test EcPoiOsmImportActionAvailableTest in wm-package

## Cosa cambia

Viene aggiunto un nuovo test in `tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php` che asserisce esplicitamente che l'azione `ImportEcPoiFromOsm` sia esposta di default sulla Nova resource `Wm\WmPackage\Nova\EcPoi`. È la stessa asserzione presente oggi in Maphub (`tests/Feature/Nova/EcPoiOsmImportActionAvailableTest.php`), ricreata qui puntando alla resource del package invece che allo stub applicativo. **Ordine di esecuzione vincolante**: questo test va scritto e mergiato per primo — solo dopo, in Maphub, si bumpa il puntatore submodule e si elimina il file gemello (vedi overview Maphub e `plan.md`).

## Perché

Dopo oc:8239, tutta la logica di import EcPoi da OSM (Nova Action, servizi, resource) vive in wm-package. Il test gemello rimasto in Maphub referenziava direttamente `Wm\WmPackage\Nova\Actions\ImportEcPoiFromOsm`, accoppiando la correttezza del test in Maphub allo stato del puntatore submodule — causa verificata di un errore PHPStan reale in CI (run #30 della PR oc:8239 #14: il submodule era ancora al commit `edf22b2d6`, che non conteneva la classe).

La copertura esistente in questo file (6 test su autorizzazione: super-admin, Administrator non-super-admin, Editor, Guest) verifica già *indirettamente* che l'azione sia esposta — tramite `resolveImportEcPoiFromOsmAction()`, che fallirebbe con un errore poco leggibile se l'azione sparisse dalla lista. Manca però un'asserzione esplicita e diretta dedicata a questo comportamento, che è quella che viene aggiunta ora.

## Requisiti

- [ ] Nuovo test in `ImportEcPoiFromOsmActionTest.php`: risolve `actions()` da `new EcPoiResource(new EcPoi)` (stesso pattern già usato da `resolveImportEcPoiFromOsmAction()` nel file) e asserisce `collect($actions)->contains(fn ($a) => $a instanceof ImportEcPoiFromOsm)`
- [ ] Il test non richiede autenticazione/ruolo specifico (a differenza degli altri 6 test del file) — verifica solo la presenza nella lista, non l'autorizzazione
- [ ] Suite Pest del package verde dopo l'aggiunta

## Rischi

- **Coordinamento cross-repo**: questa PR (wm-package) e quella gemella in Maphub sono indipendenti su CI separate. Devono essere mergiate in ordine (prima questa, poi Maphub) e ravvicinate nel tempo — altrimenti si crea una finestra con solo duplicazione di copertura senza che l'obiettivo del ticket (eliminare l'accoppiamento) sia raggiunto.
- **Ridondanza accettata consapevolmente**: il nuovo test non copre uno scenario nuovo — i 6 test di autorizzazione esistenti già fallirebbero indirettamente (errore poco leggibile) se l'azione sparisse da `EcPoi::actions()`. Si accetta la duplicazione per un messaggio d'errore esplicito; refactoring futuro fuori scope.
- **Root cause non risolta a livello strutturale**: altri consumer di wm-package (camminiditalia, osm2cai2) potrebbero avere lo stesso pattern di test accoppiati a classi interne del package e restare esposti allo stesso rischio. Nessun audit trasversale previsto in questo ciclo.

## Out of scope

- Nessuna modifica alla logica di `EcPoi::actions()`, `RolesAndPermissionsService`, o al comportamento dell'azione
- Nessuna modifica ai 6 test di autorizzazione già esistenti nel file — solo aggiunta di un nuovo `it()`
- Nessuna modifica ad altri file del package (`ImportEcPoiFromOsm.php`, `EcPoi.php` Nova resource, ecc.)

## Moduli toccati

- wm-package: `tests/Feature/Nova/Actions/ImportEcPoiFromOsmActionTest.php` (nuovo test aggiunto)
- Maphub (repo collegato, non toccato in questa PR): `tests/Feature/Nova/EcPoiOsmImportActionAvailableTest.php` (da eliminare in una PR successiva a questa — vedi overview Maphub)
