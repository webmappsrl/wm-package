<?php

namespace Wm\WmPackage\TrailRegistry\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\TrailCodeParser;
use Wm\WmPackage\TrailRegistry\TrailCodeRegistrationOutcome;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Legge i codici storici dei sentieri e li carica nel registro.
 *
 * Tre modalita': `--dry-run` (nessuna scrittura, solo rapporto), `--force`
 * (scrive senza chiedere, per l'uso da script), nessuna opzione (chiede
 * conferma interattiva prima di scrivere).
 *
 * Cosa entra e cosa resta fuori (vedi task-13-brief.md):
 * - codice leggibile, posizione libera -> riga `assigned`, legata all'EcTrack;
 * - codice leggibile, posizione gia' occupata -> non entra nel registro, che
 *   ospita solo codici realmente portati da qualcuno; il caso finisce fra le
 *   anomalie come `codice_gia_assegnato`, con accanto il sentiero che quel
 *   numero lo porta gia'. E' il
 *   database a deciderlo (violazione dell'indice unico parziale, 23505), non
 *   un controllo applicativo che lascerebbe la finestra fra il guardare e lo
 *   scrivere;
 * - codice fuori forma, o geometria fuori da ogni settore -> non entra,
 *   contato ed elencato: senza settore non c'e' prefisso, quindi non esiste
 *   un codice da comporre.
 *
 * Una transazione per sentiero, non una per tutto il comando: su centinaia di
 * record un errore a meta' non deve annullare il lavoro gia' buono.
 *
 * Idempotente: una seconda esecuzione non crea doppioni di se' stessa. Prima
 * di scrivere si verifica che non esista gia' una riga ATTIVA (reserved o
 * assigned) per quell'ec_track_id.
 */
class TrailRegistryNormalizeCommand extends Command
{
    protected $signature = 'wm-package:trail-registry-normalize {--dry-run} {--force}';

    protected $description = 'Carica nel registro dei codici i codici storici dei sentieri, elencando i casi anomali';

    public function handle(TrailRegistryService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force && ! $this->confirm('Scrivere nel registro i codici letti? Nessuna opzione annulla senza scrivere.')) {
            $this->line('Operazione annullata: nessuna scrittura effettuata.');

            return self::SUCCESS;
        }

        $property = $this->legacyCodeProperty();

        /** @var array<int, array<string, mixed>> $anomalies */
        $anomalies = [];

        // Le geometrie doppie si cercano PRIMA di registrare, non a fine
        // corsa: un sentiero che condivide la traccia con un altro non entra
        // nel registro, e per saltarlo bisogna saperlo in anticipo.
        $duplicateGeometries = $dryRun ? ['anomalies' => [], 'ids' => []] : $this->duplicateGeometryAnomalies();
        /** @var array<int, true> $duplicateIds */
        $duplicateIds = $duplicateGeometries['ids'];

        $tracksExamined = 0;
        $outsideAnySector = 0;
        $sectorMismatch = 0;
        $unparsable = 0;
        $byFullCodeAndTail = [];
        $assigned = 0;
        $alreadyAssigned = 0;
        $duplicateSkipped = 0;
        $alreadyRegistered = 0;
        $fromName = 0;

        DB::table('ec_tracks')
            ->select('id', 'name', 'properties')
            ->selectRaw('ST_AsText(geometry) AS wkt')
            // Il nome della proprieta' e' un parametro legato, mai
            // interpolato nella stringa della query: e' configurazione, non
            // input utente, ma una chiave scritta male deve dare un errore
            // chiaro (vedi legacyCodeProperty()) e mai SQL rotto.
            ->whereRaw("COALESCE(properties->>?, '') <> ''", [$property])
            ->orderBy('id')
            ->chunk(200, function ($tracks) use (
                $service,
                $dryRun,
                $property,
                &$anomalies,
                &$tracksExamined,
                &$outsideAnySector,
                &$sectorMismatch,
                &$unparsable,
                &$byFullCodeAndTail,
                &$assigned,
                &$alreadyAssigned,
                &$alreadyRegistered,
                &$duplicateSkipped,
                $duplicateIds,
            ) {
                foreach ($tracks as $track) {
                    $tracksExamined++;

                    $properties = json_decode($track->properties, true) ?? [];
                    $ref = $properties[$property] ?? '';

                    // Geometria condivisa con un altro sentiero: finche' non
                    // si sa quale delle due tracce sia quella buona, nessuna
                    // delle due prende un numero. L'anomalia e' gia' stata
                    // scritta dalla passata iniziale.
                    if (! $dryRun && isset($duplicateIds[(int) $track->id])) {
                        $duplicateSkipped++;

                        continue;
                    }

                    if ($dryRun) {
                        // In prova a vuoto non si scrive nulla: la regola di
                        // registrazione (TrailRegistryService::registerExistingCode())
                        // scrive sempre quando ref e settore sono validi, quindi
                        // qui il rapporto si costruisce leggendo tail/settore
                        // direttamente, senza invocarla.
                        $tail = TrailCodeParser::parseTail($ref);

                        if ($tail === null) {
                            $unparsable++;
                            $this->line("  codice non interpretabile: #{$track->id} «{$ref}»");

                            continue;
                        }

                        try {
                            $sector = $service->resolveSector($track->wkt);
                        } catch (SectorNotFoundException) {
                            $outsideAnySector++;
                            $this->line("  fuori da ogni settore: #{$track->id} «{$ref}»");

                            continue;
                        }

                        $fullCode = $sector->properties['full_code'];

                        // La cifra del settore scritta nel codice deve coincidere
                        // con quella dedotta dalla geometria: e' una misura della
                        // qualita' del dato, gratis perche' entrambe sono qui.
                        if (TrailCodeParser::sectorDigitFrom($ref) !== substr($fullCode, 4, 1)) {
                            $sectorMismatch++;
                            $this->line("  settore discordante: #{$track->id} «{$ref}» geometria dice {$fullCode}");
                        }

                        $key = $fullCode.'-'.$tail['number'].'-'.$tail['variant'];
                        $byFullCodeAndTail[$key][] = $track->id;

                        continue;
                    }

                    $outcome = $service->registerExistingCode((int) $track->id, $ref, $track->wkt);

                    if ($outcome->status === 'unparsableRef') {
                        $unparsable++;
                        $this->line("  codice non interpretabile: #{$track->id} «{$ref}»");
                        $anomalies[] = $this->anomaly(
                            (int) $track->id,
                            TrailRegistryAnomalyType::CodiceIlleggibile,
                            context: [
                                'track' => $this->trackRef((int) $track->id, $track->name, $track->properties),
                                'raw_code' => $ref,
                                // Il codice che la piattaforma darebbe si
                                // calcola dopo, a registro pieno: proporre
                                // un numero mentre gli altri si stanno
                                // ancora scrivendo lo darebbe gia' occupato.
                                'proposed_code' => null,
                            ],
                        );

                        continue;
                    }

                    if ($outcome->status === 'noSector') {
                        $outsideAnySector++;
                        $this->line("  fuori da ogni settore: #{$track->id} «{$ref}»");
                        $anomalies[] = $this->anomaly(
                            (int) $track->id,
                            TrailRegistryAnomalyType::FuoriDaOgniSettore,
                            context: [
                                'track' => $this->trackRef((int) $track->id, $track->name, $track->properties),
                                'raw_code' => $ref,
                            ],
                        );

                        continue;
                    }

                    if ($outcome->status === 'sectorMismatch') {
                        $sectorMismatch++;
                        $this->line("  settore discordante: #{$track->id} «{$ref}» geometria dice {$outcome->fullCode}");
                        $anomalies[] = $this->anomaly(
                            (int) $track->id,
                            TrailRegistryAnomalyType::SettoreDiscordante,
                            context: [
                                'track' => $this->trackRef((int) $track->id, $track->name, $track->properties),
                                // Quello che c'e' scritto nei dati, e quello
                                // che la piattaforma comporrebbe tenendo il
                                // settore della geometria e la coda letta.
                                'raw_code' => $ref,
                                'proposed_code' => $this->composeCode(
                                    (string) $outcome->fullCode,
                                    (int) $outcome->number,
                                    (string) $outcome->variant,
                                ),
                            ],
                        );

                        continue;
                    }

                    $key = $outcome->fullCode.'-'.$outcome->number.'-'.$outcome->variant;
                    $byFullCodeAndTail[$key][] = $track->id;

                    if ($outcome->status === 'alreadyRegistered') {
                        $alreadyRegistered++;

                        continue;
                    }

                    if ($outcome->status === 'assigned') {
                        $assigned++;
                    } else {
                        $alreadyAssigned++;
                        $this->line("  codice gia' assegnato ad altri: #{$track->id} «{$ref}»");
                        $anomalies[] = $this->alreadyAssignedAnomaly($track, $outcome);
                    }
                }
            });

        if (! $dryRun) {
            // Due rilevamenti che il ciclo sopra non puo' fare: riguardano le
            // tracce SENZA la proprieta' del codice (escluse dalla query) e
            // l'intera tabella vista tutta insieme.
            $codeInName = $this->registerCodesFromName($service, $property, $duplicateIds);
            $fromName = $codeInName['fromName'];

            $anomalies = array_merge(
                $anomalies,
                $codeInName['anomalies'],
                $duplicateGeometries['anomalies'],
            );

            $this->fillProposedCodes($service, $anomalies);

            $this->rewriteAnomalies($anomalies);
        }

        $duplicates = array_filter($byFullCodeAndTail, fn (array $ids) => count($ids) > 1);

        $this->newLine();
        $this->info($dryRun ? 'Rapporto (nessuna scrittura effettuata)' : 'Rapporto');
        $this->line('  tracce esaminate: '.$tracksExamined);
        $this->line('  posizioni distinte: '.count($byFullCodeAndTail));
        $this->line('  fuori da ogni settore: '.$outsideAnySector);
        $this->line('  settore discordante: '.$sectorMismatch);
        $this->line('  codice non interpretabile: '.$unparsable);

        if ($dryRun) {
            $this->line('  doppioni reali: '.count($duplicates));

            foreach ($duplicates as $key => $ids) {
                $this->line("    {$key}: tracce ".implode(', ', $ids));
            }
        } else {
            $this->line('  assegnati: '.$assigned);
            $this->line('  codice gia\' di altri (restano senza numero): '.$alreadyAssigned);
            $this->line('  saltati (gia\' registrati): '.$alreadyRegistered);
            $this->line('  presi dal nome: '.$fromName);
            $this->line('  saltati per geometria doppia: '.$duplicateSkipped);
        }

        return self::SUCCESS;
    }

    /**
     * In quale proprieta' del tracciato vive il codice storico.
     *
     * Il nome finisce dentro un `->>` in SQL: qui e' validato contro
     * un'espressione stretta prima di essere usato, cosi' una chiave scritta
     * male da' un errore leggibile invece di una query rotta. Nella query e'
     * comunque passato come parametro legato, non interpolato.
     */
    protected function legacyCodeProperty(): string
    {
        $property = (string) config('wm-package.features.trail_registry.legacy_code_property', 'ref');

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $property)) {
            throw new \InvalidArgumentException(
                "features.trail_registry.legacy_code_property non e' un nome di proprieta' valido: «{$property}»."
            );
        }

        return $property;
    }

    /**
     * @return array<string, mixed>
     */
    protected function anomaly(
        int $ecTrackId,
        TrailRegistryAnomalyType $type,
        ?int $relatedEcTrackId = null,
        array $context = [],
    ): array {
        return [
            'ec_track_id' => $ecTrackId,
            'type' => $type->value,
            'related_ec_track_id' => $relatedEcTrackId,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Come si presenta un sentiero dentro il contesto di un'anomalia: il
     * numero per riconoscerlo, il nome per leggerlo, l'indirizzo della sua
     * scheda alla fonte per andarla a correggere.
     *
     * Nome e indirizzo si copiano nel contesto invece di essere risolti in
     * lettura: la lista si riscrive per intero a ogni esecuzione, quindi non
     * invecchia, e cosi' mostrarla non costa una query per riga.
     *
     * @return array{id: int, name: string, url: string|null}
     */
    protected function trackRef(int $id, ?string $rawName, ?string $rawProperties): array
    {
        $properties = json_decode((string) $rawProperties, true);
        $urlProperty = (string) config('wm-package.features.trail_registry.source_url_property', '');

        return [
            'id' => $id,
            'name' => $this->readableName($rawName) ?? ('#'.$id),
            'url' => $urlProperty !== '' && is_array($properties)
                ? (is_string($url = data_get($properties, $urlProperty)) ? $url : null)
                : null,
        ];
    }

    /**
     * Il nome del sentiero in una lingua leggibile: la colonna e' tradotta
     * (JSON), e qui serve una stringa sola.
     */
    protected function readableName(?string $rawName): ?string
    {
        if ($rawName === null || trim($rawName) === '') {
            return null;
        }

        $decoded = json_decode($rawName, true);

        if (! is_array($decoded)) {
            return $rawName;
        }

        // Le lingue da provare vengono dalla configurazione
        // dell'applicazione, non da un elenco fisso: un catasto in un'altra
        // lingua non ha motivo di vedersi preferire l'italiano.
        $locales = array_unique(array_filter([
            (string) config('app.locale'),
            (string) config('app.fallback_locale'),
        ]));

        foreach ($locales as $locale) {
            if (isset($decoded[$locale]) && is_string($decoded[$locale]) && trim($decoded[$locale]) !== '') {
                return $decoded[$locale];
            }
        }

        $strings = array_filter($decoded, 'is_string');

        return $strings === [] ? null : (string) reset($strings);
    }

    /**
     * Il sentiero rimasto senza numero perche' quel codice ce l'ha gia' un
     * altro.
     *
     * Si costruisce dall'ESITO della registrazione, non dallo stato del
     * registro: ora che questo caso non lascia una riga, non c'e' piu' nulla
     * da rileggere in tabella — ed e' proprio l'assenza di quella riga a
     * rendere l'esito affidabile a ogni esecuzione, perche' la guardia di
     * idempotenza non scatta e il tentativo viene sempre rifatto.
     *
     * Chi il codice lo porta arriva dall'esito, che lo ha cercato subito dopo il
     * rifiuto dell'indice unico.
     */
    protected function alreadyAssignedAnomaly(object $track, TrailCodeRegistrationOutcome $outcome): array
    {
        $assignee = $outcome->holder?->ecTrack;

        return $this->anomaly(
            (int) $track->id,
            TrailRegistryAnomalyType::CodiceGiaAssegnato,
            relatedEcTrackId: $outcome->holder?->ec_track_id,
            context: [
                'track' => $this->trackRef((int) $track->id, $track->name, $track->properties),
                'code' => $this->composeCode(
                    (string) $outcome->fullCode,
                    (int) $outcome->number,
                    (string) $outcome->variant,
                ),
                // Chi il codice ce l'ha gia': non una contesa fra pari,
                // uno dei due il numero lo porta e l'altro no.
                'assigned_to' => $assignee === null ? null : $this->trackRef(
                    (int) $assignee->id,
                    json_encode($assignee->getTranslations('name'), JSON_UNESCAPED_UNICODE),
                    json_encode($assignee->properties, JSON_UNESCAPED_UNICODE),
                ),
            ],
        );
    }

    /**
     * Il codice in forma leggibile: prefisso del settore, numero a due
     * cifre, variante omessa quando vale `0` — come fa l'accessor `code` del
     * modello, che qui non si puo' usare perche' la riga non esiste.
     */
    protected function composeCode(string $fullCode, int $number, string $variant): string
    {
        return $fullCode
            .str_pad((string) $number, 2, '0', STR_PAD_LEFT)
            .($variant === '0' ? '' : $variant);
    }

    /**
     * Registra i codici delle tracce SENZA la proprieta' dedicata, leggendoli
     * dal nome.
     *
     * Non produce anomalie per il solo fatto che il codice stia nel nome: se
     * si puo' leggere, si registra, e il registro annota la provenienza
     * (`TrailCodeOrigin::Nome`). La lista di lavoro raccoglie i sentieri
     * rimasti SENZA numero, non quelli che il numero ce l'hanno per una via
     * meno ordinata.
     *
     * Le regole di registrazione sono le stesse di sempre — se la posizione
     * e' occupata resta senza numero e finisce fra i contesi: venire dal nome
     * non da' precedenza a nessuno, ed e' l'unica anomalia che questo
     * percorso puo' generare.
     *
     * Se dal nome non esce nulla, la traccia non ha un codice da nessuna
     * parte: non e' un errore, e' un sentiero che un codice non ce l'ha.
     *
     * @return array{anomalies: array<int, array<string, mixed>>, fromName: int}
     */
    protected function registerCodesFromName(TrailRegistryService $service, string $property, array $duplicateIds): array
    {
        $anomalies = [];
        $fromName = 0;

        DB::table('ec_tracks')
            ->select('id', 'name', 'properties')
            ->selectRaw('ST_AsText(geometry) AS wkt')
            ->whereRaw("COALESCE(properties->>?, '') = ''", [$property])
            ->orderBy('id')
            ->chunk(200, function ($tracks) use (&$anomalies, &$fromName, $service, $duplicateIds) {
                foreach ($tracks as $track) {
                    if (isset($duplicateIds[(int) $track->id])) {
                        continue;
                    }

                    $suggested = $this->codeFromName($track->name);

                    if ($suggested === null) {
                        continue;
                    }

                    if ($track->wkt === null) {
                        // Senza geometria non c'e' modo di risolvere il
                        // settore, quindi nemmeno di comporre il codice.
                        continue;
                    }

                    $outcome = $service->registerExistingCode(
                        (int) $track->id,
                        $suggested,
                        $track->wkt,
                        TrailCodeOrigin::Nome,
                    );

                    if ($outcome->status === 'assigned') {
                        $fromName++;
                    }

                    if ($outcome->status === 'alreadyAssigned') {
                        $anomalies[] = $this->alreadyAssignedAnomaly($track, $outcome);
                    }
                }
            });

        return ['anomalies' => $anomalies, 'fromName' => $fromName];
    }

    /**
     * Il codice scritto nel nome, riconosciuto dall'espressione in
     * `name_code_pattern` — di regola le PARENTESI FINALI.
     *
     * E' la convenzione che i dati reali di Sardegna Sentieri mostrano —
     * `(G 106)`, `( B 442 )`, `(D 180 A)` — e la ristrettezza e' il punto;
     * una fonte con un'altra convenzione cambia l'espressione in
     * configurazione, e chi non ne ha nessuna la svuota. Cercare un codice ovunque
     * nel nome produce due errori misurati sui dati veri: raccoglie gli
     * itinerari, che un codice non ce l'hanno (`C100T - Via Catalana`), e su
     * nomi come `Bivio 104-104A - ... (G 104A)` suggerisce il riferimento al
     * bivio invece del codice. Un suggerimento sbagliato e' peggio di nessun
     * suggerimento: e' cio' che il gestore ricopierebbe nella scheda.
     *
     * Se dalle parentesi non esce un codice interpretabile: nessuna anomalia.
     *
     * Il nome e' una colonna tradotta (JSON): si guardano tutte le lingue.
     */
    protected function codeFromName(?string $rawName): ?string
    {
        if ($rawName === null || trim($rawName) === '') {
            return null;
        }

        $decoded = json_decode($rawName, true);
        $candidates = is_array($decoded) ? array_filter($decoded, 'is_string') : [$rawName];

        $pattern = (string) config(
            'wm-package.features.trail_registry.name_code_pattern',
            '/\(([^()]*)\)\s*$/',
        );

        // Vuota: questa fonte non scrive codici nel nome, e non si prova
        // nemmeno a indovinarli.
        if (trim($pattern) === '') {
            return null;
        }

        foreach ($candidates as $name) {
            if (! preg_match($pattern, trim($name), $matches)) {
                continue;
            }

            $candidate = trim($matches[1]);

            if ($candidate !== '' && TrailCodeParser::parseTail($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Le tracce che condividono la geometria con un'altra.
     *
     * Una query sola sull'intera tabella, raggruppando per impronta della
     * geometria: il confronto a due a due dentro il ciclo costerebbe
     * centinaia di migliaia di paragoni per nulla.
     *
     * @return array{anomalies: array<int, array<string, mixed>>, ids: array<int, true>}
     */
    protected function duplicateGeometryAnomalies(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT string_agg(id::text, ',' ORDER BY id) AS ids
            FROM ec_tracks
            WHERE geometry IS NOT NULL
            GROUP BY md5(ST_AsText(geometry))
            HAVING count(*) > 1
        SQL);

        $anomalies = [];
        $affected = [];

        foreach ($rows as $row) {
            $ids = array_map('intval', explode(',', (string) $row->ids));

            // I gemelli si leggono una volta sola per gruppo: servono nome e
            // indirizzo alla fonte di ciascuno, e i gruppi sono piccoli.
            $tracks = DB::table('ec_tracks')
                ->select('id', 'name', 'properties')
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            foreach ($ids as $id) {
                $affected[$id] = true;
                $others = array_values(array_diff($ids, [$id]));

                $twins = [];

                foreach ($others as $otherId) {
                    $other = $tracks->get($otherId);

                    $twins[] = $other === null
                        ? ['id' => $otherId, 'name' => '#'.$otherId, 'url' => null]
                        : $this->trackRef($otherId, $other->name, $other->properties);
                }

                $self = $tracks->get($id);

                $anomalies[] = $this->anomaly(
                    $id,
                    TrailRegistryAnomalyType::GeometriaDuplicata,
                    relatedEcTrackId: $others[0] ?? null,
                    context: [
                        'track' => $self === null
                            ? ['id' => $id, 'name' => '#'.$id, 'url' => null]
                            : $this->trackRef($id, $self->name, $self->properties),
                        'twins' => $twins,
                    ],
                );
            }
        }

        return ['anomalies' => $anomalies, 'ids' => $affected];
    }

    /**
     * Completa le anomalie da codice illeggibile con il numero che la
     * piattaforma darebbe a quel sentiero.
     *
     * Si fa alla fine, non mentre il ciclo gira: il primo numero libero di
     * un settore ha senso solo a registro completo, altrimenti si
     * suggerirebbe un numero che verrebbe occupato pochi record dopo.
     *
     * E' un suggerimento, non un'assegnazione: quel sentiero un codice ce
     * l'aveva gia' — solo scritto male — e sostituirlo d'ufficio darebbe un
     * numero diverso da quello che sta sulla segnaletica in campo. Decide il
     * gestore, sistemando la scheda alla fonte.
     *
     * @param  array<int, array<string, mixed>>  $anomalies
     */
    protected function fillProposedCodes(TrailRegistryService $service, array &$anomalies): void
    {
        foreach ($anomalies as $index => $anomaly) {
            if ($anomaly['type'] !== TrailRegistryAnomalyType::CodiceIlleggibile->value) {
                continue;
            }

            $wkt = DB::table('ec_tracks')
                ->where('id', $anomaly['ec_track_id'])
                ->value(DB::raw('ST_AsText(geometry)'));

            if (! is_string($wkt) || $wkt === '') {
                continue;
            }

            try {
                $proposal = $service->propose($wkt);
            } catch (\Throwable) {
                // Settore introvabile o esaurito: si resta senza
                // suggerimento, che e' meglio di uno sbagliato.
                continue;
            }

            $context = json_decode((string) $anomaly['context'], true);
            $context['proposed_code'] = $this->composeCode(
                $proposal['region'].$proposal['province'].$proposal['area'].$proposal['sector'],
                $proposal['number'],
                $proposal['variant'],
            );

            $anomalies[$index]['context'] = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * Le anomalie si riscrivono da zero: si cancella tutto e si reinserisce,
     * in una transazione.
     *
     * E' cio' che fa sparire una riga quando la scheda e' stata sistemata
     * alla fonte, ed e' il motivo per cui questa lista ha senso come lista di
     * lavoro. Lo storico non serve: vive nel registro dei codici, non qui.
     *
     * @param  array<int, array<string, mixed>>  $anomalies
     */
    protected function rewriteAnomalies(array $anomalies): void
    {
        $now = now();

        DB::transaction(function () use ($anomalies, $now) {
            TrailRegistryAnomaly::query()->delete();

            foreach (array_chunk($anomalies, 500) as $chunk) {
                DB::table('trail_registry_anomalies')->insert(array_map(
                    fn (array $row) => [...$row, 'created_at' => $now],
                    $chunk,
                ));
            }
        });
    }
}
