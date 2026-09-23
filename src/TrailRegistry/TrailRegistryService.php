<?php

namespace Wm\WmPackage\TrailRegistry;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeOrigin;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailCodeTransitionException;
use Wm\WmPackage\TrailRegistry\Exceptions\NumberOccupiedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorExhaustedException;
use Wm\WmPackage\TrailRegistry\Exceptions\SectorNotFoundException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCodeEvent;

/**
 * Unico service di dominio del catasto.
 */
class TrailRegistryService
{
    /**
     * Tentativi di riserva prima di arrendersi. Serve a non girare a vuoto se
     * il settore e' davvero esaurito, caso che ricade in
     * SectorExhaustedException.
     */
    protected const RESERVE_ATTEMPTS = 5;

    /**
     * Il settore CAI in cui ricade una geometria.
     *
     * Il filtro sulla sorgente (`sector_source`, di regola `osm2cai`) e sulla
     * presenza di `full_code` non e'
     * una rifinitura: senza, la traccia interseca anche comune, provincia e
     * regione (420 poligoni amministrativi estranei alla numerazione). Le due
     * condizioni coincidono sui dati (63 record, 63 con full_code) ma vanno
     * messe entrambe — la prima dice l'origine, la seconda impedisce che un
     * futuro record osm2cai incompleto entri silenziosamente.
     *
     * `taxonomy_wheres.geometry` e' `geography`, non `geometry`: il filtro va
     * scritto SENZA cast (`ST_Intersects(geometry, ST_GeomFromText(...)::geography)`)
     * per far usare l'indice GiST (Index Scan, `geometry && ...`) — misurato
     * con `enable_seqscan = off`. Il cast a `::geometry` va applicato SOLO
     * dentro `ST_Intersection` (che in geography non esiste), sul
     * sottoinsieme gia' ristretto dal filtro. La forma con doppio cast
     * (`geometry::geometry` nel filtro, come in
     * GeometryComputationService.php:66) ignora l'indice e passa a Seq Scan:
     * sul database reale la stessa query ha impiegato oltre due minuti in
     * pianificazione con quella forma.
     *
     * Tre esiti: un solo settore (il caso normale), piu' settori perche' un
     * sentiero lungo li attraversa — e allora vince quello in cui corre piu' a
     * lungo, calcolato nella stessa query — oppure nessuno, che solleva.
     *
     * NON usare GeometryModel::getOrderedTaxonomyWheres(): legge una copia
     * gia' calcolata in properties['taxonomy_where'] popolata da OSMFeatures
     * (un'altra sorgente rispetto a OSM2CAI), non tocca la geometria,
     * restituisce solo nomi senza identificativi ne' full_code, ordina per
     * livello amministrativo (criterio senza rapporto con la numerazione CAI),
     * e su una traccia mai elaborata torna vuoto in silenzio.
     */
    public function resolveSector(string $geometryWkt): TaxonomyWhere
    {
        $source = (string) config('wm-package.features.trail_registry.sector_source', 'osm2cai');

        $row = DB::selectOne(
            <<<'SQL'
            SELECT tw.id
            FROM taxonomy_wheres tw
            WHERE tw.properties->>'source' = ?
              AND COALESCE(tw.properties->>'full_code', '') <> ''
              AND ST_Intersects(tw.geometry, ST_GeomFromText(?, 4326)::geography)
            ORDER BY ST_Length(
                ST_Intersection(tw.geometry::geometry, ST_GeomFromText(?, 4326))::geography
            ) DESC
            LIMIT 1
            SQL,
            [$source, $geometryWkt, $geometryWkt],
        );

        if ($row === null) {
            throw SectorNotFoundException::forGeometry();
        }

        return TaxonomyWhere::findOrFail($row->id);
    }

    /**
     * Il primo numero libero del settore in cui ricade la geometria.
     *
     * NON scrive: la preistruttoria deve poter mostrare un numero prima di
     * riservarlo, altrimenti ogni traccia scartata lascerebbe dietro un numero
     * bloccato per sempre.
     *
     * Occupato significa riservato O assegnato, in un unico registro. Una riga
     * liberata non occupa: il numero e' riassegnabile.
     *
     * Si prova per prima la posizione senza variante, scorrendo i numeri
     * liberi del settore nell'ordine di vicinanza geografica alla traccia in
     * esame (vedi orderByProximity()), e si passa alle lettere solo quando
     * l'intero settore senza variante e' esaurito — ma e' l'ordine di
     * ricerca, non il significato di `0`: ZNUB535 e ZNUB535A sono due
     * sentieri indipendenti, non uno la diramazione dell'altro.
     *
     * ATTENZIONE, cicli NON invertibili: il ciclo esterno e' la variante,
     * quello interno e' il numero (variante-poi-numero, non numero-poi-
     * variante). Invertirli sembra equivalente ma non lo e': con ZNUB500
     * occupato e nessun altro numero occupato, l'ordine numero-poi-variante
     * proporrebbe ZNUB500A (stesso numero 0, variante successiva) invece di
     * ZNUB501 — cioe' tratterebbe la variante come una diramazione del
     * numero, esattamente il fraintendimento che il requisito esclude. Il
     * requisito e' la contiguita' NUMERICA: si esauriscono i cento numeri
     * in variante '0' prima di toccare le lettere. Verificato con un test
     * dedicato (ProposeTest::"salta i numeri occupati...").
     *
     * @return array{taxonomy_where_id: int, region: string, province: string, area: string, sector: string, number: int, variant: string}
     */
    public function propose(string $geometryWkt): array
    {
        $sector = $this->resolveSector($geometryWkt);
        $fullCode = $sector->properties['full_code'];

        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->whereIn('status', $this->activeStatusValues())
            ->get(['number', 'variant'])
            ->map(fn ($row) => $row->number.':'.$row->variant)
            ->all();

        $distances = $this->usedNumbersWithDistance($fullCode, $geometryWkt);

        foreach ($this->variantSearchOrder() as $variant) {
            $free = array_values(array_filter(
                range(0, 99),
                fn (int $number) => ! in_array($number.':'.$variant, $taken, true),
            ));

            $ordered = $this->orderByProximity($free, $distances);

            if ($ordered !== []) {
                return [
                    'taxonomy_where_id' => $sector->id,
                    'region' => substr($fullCode, 0, 1),
                    'province' => substr($fullCode, 1, 2),
                    'area' => substr($fullCode, 3, 1),
                    'sector' => substr($fullCode, 4, 1),
                    'number' => $ordered[0],
                    'variant' => $variant,
                ];
            }
        }

        throw SectorExhaustedException::forFullCode($fullCode);
    }

    /**
     * I numeri liberi di un settore, senza variante: e' l'elenco che serve al
     * gestore per sostituire a mano il numero proposto.
     *
     * @return array<int, int>
     */
    public function availableNumbers(string $fullCode): array
    {
        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->where('variant', '0')
            ->whereIn('status', $this->activeStatusValues())
            ->pluck('number')
            ->all();

        return array_values(array_diff(range(0, 99), $taken));
    }

    /**
     * Le varianti ancora libere per un numero, nell'ordine in cui vanno
     * offerte: '0' («nessuna variante») per prima, poi A-Z.
     *
     * Le righe occupate si contano tutte, comprese eventuali varianti
     * numeriche entrate dall'import: sono le varianti *offerte* a essere
     * limitate alle lettere, non quelle che occupano una posizione.
     *
     * @return array<int, string>
     */
    public function availableVariants(string $fullCode, int $number): array
    {
        $taken = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->where('number', $number)
            ->whereIn('status', $this->activeStatusValues())
            ->pluck('variant')
            ->all();

        return array_values(array_diff($this->variantSearchOrder(), $taken));
    }

    /**
     * I numeri del settore che hanno almeno una variante libera: e' l'elenco
     * da cui il gestore sceglie quando sostituisce a mano.
     *
     * Non coincide con availableNumbers(), che risponde a un'altra domanda —
     * quali numeri *puri* sono liberi — e serve a propose(). Qui un numero
     * gia' occupato resta in elenco finche' gli avanza una lettera: e' cio'
     * che rende raggiungibile la variante di un sentiero esistente.
     *
     * Una query sola sul settore: cento chiamate a availableVariants()
     * sarebbero cento query a ogni apertura del modale.
     *
     * Ordinato per vicinanza quando si passa `$geometryWkt` (oc:8570): e' lo
     * stesso criterio di propose(), applicato qui alla lista che alimenta la
     * sostituzione manuale. Senza il parametro l'ordine resta quello di oggi
     * — numerico — perche' altri consumer chiamano questo metodo col solo
     * `fullCode` e non devono rompersi.
     *
     * Un numero gia' occupato con una lettera ancora libera concorre per
     * vicinanza come un numero libero: se appartiene al cluster piu' vicino
     * alla traccia, offrire la sua variante (es. ZNUB511A accanto a ZNUB511)
     * e' un'offerta legittima quanto un numero nuovo nella stessa zona.
     *
     * `$excludeCodeId` esclude il codice in esame dal calcolo delle distanze:
     * serve quando la geometria passata e' quella dello stesso codice che si
     * sta per sostituire (l'Action di sostituzione manuale) — altrimenti
     * quel codice distarebbe zero da se stesso, farebbe cluster da solo e
     * coprirebbe i vicini veri.
     *
     * @return array<int, int>
     */
    public function numbersWithAvailableVariants(string $fullCode, ?string $geometryWkt = null, ?int $excludeCodeId = null): array
    {
        $rows = TrailRegistryCode::query()
            ->where('region', substr($fullCode, 0, 1))
            ->where('province', substr($fullCode, 1, 2))
            ->where('area', substr($fullCode, 3, 1))
            ->where('sector', substr($fullCode, 4, 1))
            ->whereIn('status', $this->activeStatusValues())
            ->get(['number', 'variant']);

        $offered = count($this->variantSearchOrder());

        $saturated = $rows
            ->groupBy('number')
            ->filter(fn ($group) => count(
                array_intersect($this->variantSearchOrder(), $group->pluck('variant')->all())
            ) === $offered)
            ->keys()
            ->map(fn ($number) => (int) $number)
            ->all();

        $available = array_values(array_diff(range(0, 99), $saturated));

        // Senza geometria non c'e' nulla rispetto a cui misurare: resta
        // l'ordine numerico, che e' anche il comportamento dei consumer che
        // chiamano questo metodo con il solo fullCode (oc:8570).
        if ($geometryWkt === null) {
            return $available;
        }

        return $this->orderByProximity(
            $available,
            $this->usedNumbersWithDistance($fullCode, $geometryWkt, $excludeCodeId),
        );
    }

    /**
     * I numeri disponibili, ordinati per vicinanza al cluster piu' prossimo.
     *
     * La numerazione dei sentieri segue la geografia dentro i numeri liberi:
     * la disponibilita' e' il vincolo, la vicinanza e' il criterio. Prendere
     * il primo libero in assoluto — quello che il servizio faceva prima di
     * oc:8570 — produce un numero che il gestore deve quasi sempre correggere
     * a mano.
     *
     * I numeri gia' usati si raggruppano per contiguita' numerica: 11, 12, 13
     * sono un cluster, 16, 17 un altro. Governa l'ordine **il cluster piu'
     * vicino alla traccia**, e basta quello: chi vuole aprire una numerazione
     * altrove non passa dalla proposta automatica, usa
     * {@see TrailRegistryService::replaceNumber()}. Far competere piu' cluster
     * non servirebbe a nessuno e renderebbe l'ordine difficile da spiegare.
     *
     * L'ordine e' deterministico: a parita' di distanza geografica governa il
     * cluster col numero piu' basso, e a parita' di distanza numerica vince il
     * numero precedente. Senza, due chiamate consecutive potrebbero
     * restituire ordini diversi — e in un settore di sentieristica gli
     * incroci sono la norma, quindi le parita' a distanza zero pure.
     *
     * Ordinare non e' filtrare: l'array restituito contiene sempre tutti gli
     * elementi ricevuti (oc:8570).
     *
     * @param  list<int>  $available  i numeri da ordinare
     * @param  array<int, float>  $usedWithDistance  numero gia' usato => distanza in metri
     * @return list<int>
     */
    public function orderByProximity(array $available, array $usedWithDistance): array
    {
        sort($available);

        if ($usedWithDistance === []) {
            return $available;
        }

        $cluster = $this->nearestCluster($usedWithDistance);

        usort($available, function (int $a, int $b) use ($cluster) {
            $da = $this->numericDistanceFrom($cluster, $a);
            $db = $this->numericDistanceFrom($cluster, $b);

            // A parita' di distanza numerica vince il precedente, cioe' il
            // numero piu' basso: «continua quella numerazione» non dice di
            // saltare in avanti lasciando un buco dietro.
            return $da <=> $db ?: $a <=> $b;
        });

        return $available;
    }

    /**
     * I numeri del cluster piu' vicino alla traccia.
     *
     * Un cluster e' un gruppo di numeri consecutivi fra quelli gia' usati; la
     * sua distanza e' quella del suo codice piu' prossimo. A parita' vince il
     * cluster col numero piu' basso, cosi' l'esito non dipende dall'ordine in
     * cui il database ha restituito le righe (oc:8570).
     *
     * @param  array<int, float>  $usedWithDistance
     * @return list<int>
     */
    private function nearestCluster(array $usedWithDistance): array
    {
        $numbers = array_keys($usedWithDistance);
        sort($numbers);

        $clusters = [];
        $current = [];

        foreach ($numbers as $number) {
            if ($current !== [] && $number !== end($current) + 1) {
                $clusters[] = $current;
                $current = [];
            }

            $current[] = $number;
        }

        $clusters[] = $current;

        usort($clusters, function (array $a, array $b) use ($usedWithDistance) {
            $da = min(array_map(fn (int $n) => $usedWithDistance[$n], $a));
            $db = min(array_map(fn (int $n) => $usedWithDistance[$n], $b));

            return $da <=> $db ?: $a[0] <=> $b[0];
        });

        return $clusters[0];
    }

    /**
     * Quanto dista un numero dal cluster: la distanza dal suo elemento piu'
     * vicino, nelle due direzioni.
     *
     * @param  list<int>  $cluster
     */
    private function numericDistanceFrom(array $cluster, int $number): int
    {
        return min(array_map(fn (int $n) => abs($n - $number), $cluster));
    }

    /**
     * L'ordine di ricerca nello spazio della variante: prima la posizione
     * senza variante, poi le lettere.
     *
     * Le cifre da 1 a 9 NON sono in questo elenco: sono i sottosentieri del
     * modello nazionale CAI, fuori scope. Il database le ammette (il vincolo
     * e' `[0-9A-Z]`) perche' se un domani vanno accolte si allenta qui senza
     * una migration su tabella popolata.
     *
     * @return array<int, string>
     */
    protected function variantSearchOrder(): array
    {
        return array_merge(['0'], range('A', 'Z'));
    }

    /**
     * @return array<int, string>
     */
    protected function activeStatusValues(): array
    {
        return array_map(
            fn (TrailCodeStatus $status) => $status->value,
            TrailCodeStatus::active(),
        );
    }

    /**
     * I numeri gia' usati del settore con la loro distanza dalla traccia in esame.
     *
     * La geometria di un codice non sta nel registro: sta nel sentiero, o
     * nell'istanza se il codice e' solo riservato — da qui il `COALESCE`,
     * lo stesso di {@see ComposesTrailRegistryMap::neighbourCodes()}. I
     * codici che non hanno ne' l'uno ne' l'altra non partecipano: non
     * sapremmo dove metterli.
     *
     * `ST_Distance` su `geography` restituisce metri. Un numero con piu'
     * varianti compare una volta sola, con la distanza minima: e' il numero a
     * essere ordinato, non la singola riga.
     *
     * @return array<int, float>
     */
    protected function usedNumbersWithDistance(string $fullCode, string $geometryWkt, ?int $excludeCodeId = null): array
    {
        $ecTracks = (string) config('wm-package.ec_track_table', 'ec_tracks');

        $active = $this->activeStatusValues();

        $placeholders = implode(',', array_fill(0, count($active), '?'));

        // Esclude il codice in esame (oc:8570): quando la geometria e' la
        // sua, quel codice dista zero da se stesso, farebbe cluster da solo
        // e coprirebbe i vicini veri — succede aprendo l'Action di
        // sostituzione manuale, dove il codice e' gia' nel registro. In
        // propose() il codice non esiste ancora, quindi non c'e' nulla da
        // escludere e $excludeCodeId resta null.
        $excludeSql = $excludeCodeId !== null ? 'AND c.id <> ?' : '';

        $rows = DB::select(
            <<<SQL
            SELECT
                c.number,
                MIN(ST_Distance(
                    COALESCE(t.geometry, a.geometry),
                    ST_GeomFromText(?, 4326)::geography
                )) AS distance
            FROM trail_registry_codes c
            LEFT JOIN {$ecTracks} t ON t.id = c.ec_track_id
            LEFT JOIN trail_applications a ON a.id = c.trail_application_id
            WHERE c.region = ?
              AND c.province = ?
              AND c.area = ?
              AND c.sector = ?
              AND c.status IN ({$placeholders})
              AND COALESCE(t.geometry, a.geometry) IS NOT NULL
              {$excludeSql}
            GROUP BY c.number
            SQL,
            array_merge(
                [$geometryWkt],
                [
                    substr($fullCode, 0, 1),
                    substr($fullCode, 1, 2),
                    substr($fullCode, 3, 1),
                    substr($fullCode, 4, 1),
                ],
                $active,
                $excludeCodeId !== null ? [$excludeCodeId] : [],
            ),
        );

        $distances = [];

        foreach ($rows as $row) {
            $distances[(int) $row->number] = (float) $row->distance;
        }

        return $distances;
    }

    /**
     * Blocca un numero per un'istanza. E' l'unico punto in cui nasce una riga
     * nel registro: l'esistenza di quella riga E' la prova che la
     * prevalidazione e' passata.
     *
     * Se qualcuno vince la corsa sullo stesso numero, si riprova con il
     * successivo libero: il vincolo del database garantisce che due richieste
     * non ottengano lo stesso numero, non che la seconda ne ottenga uno.
     *
     * Il ritentativo vale SOLO per un candidato proposto internamente
     * (`$proposal` omesso): in quel caso nessuno ha scelto un numero preciso,
     * quindi «un numero qualunque libero» soddisfa comunque la richiesta. Un
     * `$proposal` passato esplicitamente e' invece trattato come un impegno
     * del chiamante su un numero preciso — stessa logica di replaceNumber(),
     * un solo tentativo — e se e' gia' occupato la chiamata fallisce, non
     * ripiega silenziosamente su un numero diverso: un chiamante futuro che
     * passi qui una proposta non deve scoprire a sue spese che il numero
     * restituito non e' quello che aveva chiesto. Chi ha davvero bisogno di
     * riprovare su un candidato esplicito lo faccia rileggendolo da propose().
     */
    public function reserve(TrailApplication $application, ?array $proposal = null): TrailRegistryCode
    {
        if ($proposal !== null) {
            return $this->insertReservation($proposal, $application, 'reserved_on_application');
        }

        $geometry = $this->wktOf($application);

        for ($attempt = 1; $attempt <= self::RESERVE_ATTEMPTS; $attempt++) {
            $candidate = $this->propose($geometry);

            try {
                return $this->insertReservation($candidate, $application, 'reserved_on_application');
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e) || $attempt === self::RESERVE_ATTEMPTS) {
                    throw $e;
                }
                // ritenta: il numero e' stato preso fra la proposta e la scrittura
            }
        }

        // Irraggiungibile: l'ultimo giro del ciclo rilancia sempre
        // l'eccezione (condizione sopra), quindi il ciclo non puo' terminare
        // senza restituire un codice o sollevare. Presente solo perche' il
        // tipo di ritorno del metodo lo richiede staticamente.
        throw new \LogicException('reserve(): stato non raggiungibile.');
    }

    /**
     * Scrive una riga di riserva per un candidato gia' deciso, senza
     * ritentare. Usato da reserve() (che intorno ci mette il ciclo di
     * ritentativo per un candidato auto-proposto) e da replaceNumber() per il
     * numero scelto a mano dal gestore: quella scelta e' deliberata, se il
     * numero e' occupato la sostituzione va rifiutata subito, non dirottata
     * silenziosamente su un numero diverso.
     */
    protected function insertReservation(array $candidate, TrailApplication $application, string $reason): TrailRegistryCode
    {
        return DB::transaction(function () use ($candidate, $application, $reason) {
            $code = TrailRegistryCode::create([
                'region' => $candidate['region'],
                'province' => $candidate['province'],
                'area' => $candidate['area'],
                'sector' => $candidate['sector'],
                'number' => $candidate['number'],
                'variant' => $candidate['variant'],
                'status' => TrailCodeStatus::Reserved,
                'taxonomy_where_id' => $candidate['taxonomy_where_id'],
                'trail_application_id' => $application->id,
            ]);

            $this->recordEvent($code, null, TrailCodeStatus::Reserved, $reason, $application->user_id);

            return $code;
        });
    }

    /**
     * Il codice riservato diventa definitivo e si lega al sentiero appena
     * creato. Non viene riemesso: cambia lo stato e cambia il detentore, il
     * numero resta identico — e' gia' stato comunicato al richiedente.
     */
    public function confirm(TrailRegistryCode $code, EcTrack $track, ?int $userId = null): TrailRegistryCode
    {
        $this->guardTransition($code, [TrailCodeStatus::Reserved], 'confermare');

        return DB::transaction(function () use ($code, $track, $userId) {
            $from = $code->status;

            $code->update([
                'status' => TrailCodeStatus::Assigned,
                'ec_track_id' => $track->id,
            ]);

            $this->recordEvent($code, $from, TrailCodeStatus::Assigned, 'application_approved', $userId);

            return $code->refresh();
        });
    }

    /**
     * Il numero torna disponibile. Non e' invocabile «per se'»: e' sempre la
     * conseguenza di un atto, e la causa va scritta nella storia — «liberato
     * per deaccatastamento» e «liberato perche' l'istanza e' stata respinta»
     * sono due fatti diversi che nello stato corrente si appiattirebbero
     * entrambi in `released`.
     */
    public function release(TrailRegistryCode $code, string $reason, ?int $userId = null): TrailRegistryCode
    {
        $this->guardTransition($code, [TrailCodeStatus::Reserved, TrailCodeStatus::Assigned], 'liberare');

        return DB::transaction(function () use ($code, $reason, $userId) {
            $from = $code->status;

            $code->update(['status' => TrailCodeStatus::Released]);

            $this->recordEvent($code, $from, TrailCodeStatus::Released, $reason, $userId);

            return $code->refresh();
        });
    }

    /**
     * Sostituzione a mano del numero proposto, scegliendo fra i liberi.
     *
     * Libera il vecchio e riserva il nuovo in UNA transazione: come due passi
     * separati, nel mezzo il numero appena liberato potrebbe essere preso da
     * un'altra richiesta.
     */
    public function replaceNumber(
        TrailRegistryCode $code,
        int $number,
        string $variant = '0',
        ?int $userId = null,
    ): TrailRegistryCode {
        $fullCode = $code->fullCode;

        return DB::transaction(function () use ($code, $number, $variant, $userId, $fullCode) {
            // Il guard va qui, non prima della transazione: fra il render del
            // modale e il salvataggio un'altra approvazione puo' aver portato
            // il codice ad Assigned, e sostituirlo significherebbe liberare un
            // numero gia' comunicato al richiedente.
            $code = TrailRegistryCode::query()
                ->whereKey($code->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardTransition($code, [TrailCodeStatus::Reserved], 'sostituire');

            $application = $code->application;

            $this->release($code, 'number_replaced', $userId);

            try {
                // insertReservation(), non reserve(): un numero scelto a mano
                // dal gestore e' deliberato, se e' occupato la sostituzione
                // deve fallire subito, non essere silenziosamente dirottata
                // su un numero diverso.
                return $this->insertReservation([
                    'taxonomy_where_id' => $code->taxonomy_where_id,
                    'region' => $code->region,
                    'province' => $code->province,
                    'area' => $code->area,
                    'sector' => $code->sector,
                    'number' => $number,
                    'variant' => $variant,
                ], $application, 'number_replaced');
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    throw NumberOccupiedException::forNumber($fullCode, $number, $variant);
                }

                throw $e;
            }
        });
    }

    /**
     * Un codice scritto e' formalmente corretto?
     *
     * Sette caratteri senza variante, otto con: regione (1 lettera), provincia
     * (2), area (1), settore (1 cifra), numero (2 cifre), variante opzionale
     * (1 lettera). Senza trattini: il codice REI standard non li ha.
     *
     * La variante numerica e' rifiutata: appartiene al modello dei
     * sottosentieri, fuori scope.
     *
     * Non riusa TrailCodeParser: quello legge solo la coda di un codice gia'
     * esistente e in una qualsiasi delle sue forme storiche (con trattini,
     * spazi, ecc. - vedi la sua classdoc), scartando il prefisso perche' non
     * autoritativo. Qui invece si valida l'intera stringa, prefisso compreso,
     * ed e' proprio la forma "senza separatori" del REI standard il criterio
     * di correttezza: le due funzioni verificano cose diverse su un input di
     * forma diversa, e piegare una sull'altra avrebbe richiesto di violare
     * l'una o l'altra regola.
     *
     * Il formato e' composto qui in un punto solo a partire da
     * config('wm-package.features.trail_registry.code_format'): non e' piu'
     * un'espressione fissa nel codice.
     */
    public function validate(string $code): bool
    {
        $format = config('wm-package.features.trail_registry.code_format');
        $digits = (int) ($format['number_digits'] ?? 2);

        // Prefisso: regione (1) + provincia (2) + area (1) + settore (1 cifra).
        // Coda: le cifre del numero, piu' la variante come lettera se ammessa.
        $variant = ($format['variant_letters'] ?? true) ? '[A-Z]?' : '';

        $pattern = '/^[A-Z][A-Z]{2}[A-Z]\d\d{'.$digits.'}'.$variant.'$/';

        return (bool) preg_match($pattern, $code)
            // Una coda di quattro cifre sarebbe un sottosentiero: fuori scope.
            && ! preg_match('/\d{'.($digits + 2).'}/', $code);
    }

    /**
     * Registra un codice storico letto da un `ref`: estrae la coda con
     * TrailCodeParser, ricava il settore dalla geometria, e tenta la
     * scrittura come `assigned`. Se quel codice ce l'ha gia' un altro non si
     * scrive nulla: il registro contiene solo codici realmente portati da
     * qualcuno, e il caso irrisolto torna al chiamante come esito
     * `alreadyAssigned`, con in mano il sentiero che lo porta perche' possa
     * segnalarlo.
     *
     * Estratta da TrailRegistryNormalizeCommand (task 14): il comando non e'
     * piu' l'unico chiamante, anche l'import da Sardegna Sentieri (in
     * forestas) deve fare esattamente la stessa cosa al momento
     * dell'import — la regola che decide quale numero porta un sentiero
     * vive qui, in un solo posto.
     *
     * Il WKT della geometria e' un parametro, non riletto dal modello: il
     * chiamante lo ha gia' in mano (letto in blocchi su centinaia di
     * record), e rileggerlo sarebbe una query in piu' per ogni sentiero.
     *
     * Non tutto cio' che ha un codice leggibile entra: se il settore scritto
     * nel codice non coincide con quello della geometria, la registrazione si
     * ferma. Nel registro stanno solo i codici senza anomalie; gli altri
     * vivono nella lista di lavoro finche' la fonte non e' sistemata.
     *
     * Regole che devono restare intatte in ogni chiamante: una transazione
     * per singolo sentiero (un errore a meta' non deve annullare il lavoro
     * gia' buono); a decidere chi tiene il codice e' il database, che
     * intercetta la violazione di unicita' (23505) dell'indice parziale, non
     * un controllo applicativo che lascerebbe la finestra fra il guardare e
     * lo scrivere; la guardia di idempotenza sull'ec_track_id, senza la
     * quale una seconda esecuzione produrrebbe doppioni indistinguibili da
     * quelli veri.
     *
     * `$origin` non si deduce qui: chi chiama sa da dove ha preso il codice
     * (dalla proprieta' dedicata, o dal nome), il service no — indovinarlo
     * richiederebbe passargli il nome del tracciato per un motivo che non lo
     * riguarda. Il default `CampoDedicato` mantiene invariati i chiamanti
     * esistenti (il comando, e l'aggancio all'import in un altro repository).
     */
    public function registerExistingCode(
        int $ecTrackId,
        string $ref,
        string $geometryWkt,
        TrailCodeOrigin $origin = TrailCodeOrigin::CampoDedicato,
    ): TrailCodeRegistrationOutcome {
        $tail = TrailCodeParser::parseTail($ref);

        if ($tail === null) {
            return TrailCodeRegistrationOutcome::unparsableRef();
        }

        try {
            $sector = $this->resolveSector($geometryWkt);
        } catch (SectorNotFoundException) {
            return TrailCodeRegistrationOutcome::noSector();
        }

        $fullCode = $sector->properties['full_code'];
        $sectorMismatch = TrailCodeParser::sectorDigitFrom($ref) !== substr($fullCode, 4, 1);

        // Il codice contraddice la geometria: non si registra. Il registro
        // tiene solo codici su cui non pende alcun dubbio.
        if ($sectorMismatch) {
            return TrailCodeRegistrationOutcome::sectorMismatch($fullCode, $tail['number'], $tail['variant']);
        }

        if ($this->hasExistingRegistrationForTrack($ecTrackId)) {
            return TrailCodeRegistrationOutcome::alreadyRegistered($sectorMismatch, $fullCode, $tail['number'], $tail['variant']);
        }

        $candidate = [
            'taxonomy_where_id' => $sector->id,
            'region' => substr($fullCode, 0, 1),
            'province' => substr($fullCode, 1, 2),
            'area' => substr($fullCode, 3, 1),
            'sector' => substr($fullCode, 4, 1),
            'number' => $tail['number'],
            'variant' => $tail['variant'],
        ];

        $code = $this->writeExistingCodeRow($candidate, $ecTrackId, $origin);

        return $code !== null
            ? TrailCodeRegistrationOutcome::assigned($code, $sectorMismatch, $fullCode, $tail['number'], $tail['variant'])
            : TrailCodeRegistrationOutcome::alreadyAssigned(
                $this->activeCodeAt($candidate),
                $sectorMismatch,
                $fullCode,
                $tail['number'],
                $tail['variant'],
            );
    }

    /**
     * Esiste gia' una registrazione per questo ec_track_id? E' la guardia di
     * idempotenza: senza, una seconda esecuzione produrrebbe doppioni
     * indistinguibili da quelli veri.
     *
     * Solo `released` deve permettere una nuova registrazione: e' il caso
     * legittimo di un numero liberato e poi riassegnato.
     *
     * Un sentiero il cui codice e' gia' di un altro non ha alcuna riga,
     * quindi qui risulta sempre non
     * registrato e a ogni esecuzione ritenta: e' voluto. Il caso puo'
     * essersi sciolto nel frattempo (l'altro sentiero deaccatastato, il
     * codice corretto alla fonte), e in quel caso il numero gli spetta senza
     * bisogno di alcun intervento. Se invece regge, il tentativo fallisce di
     * nuovo e l'anomalia viene riscritta identica.
     */
    protected function hasExistingRegistrationForTrack(int $ecTrackId): bool
    {
        return TrailRegistryCode::query()
            ->where('ec_track_id', $ecTrackId)
            ->whereIn('status', array_column(TrailCodeStatus::active(), 'value'))
            ->exists();
    }

    /**
     * La riga attiva che occupa una posizione, se c'e'. L'indice unico
     * parziale garantisce che sia una sola.
     *
     * @param  array<string, mixed>  $candidate
     */
    protected function activeCodeAt(array $candidate): ?TrailRegistryCode
    {
        return TrailRegistryCode::query()
            ->where('region', $candidate['region'])
            ->where('province', $candidate['province'])
            ->where('area', $candidate['area'])
            ->where('sector', $candidate['sector'])
            ->where('number', $candidate['number'])
            ->where('variant', $candidate['variant'])
            ->whereIn('status', array_column(TrailCodeStatus::active(), 'value'))
            ->first();
    }

    /**
     * Scrive la riga `assigned` per il sentiero, in una transazione dedicata
     * a questo solo sentiero. Se l'indice unico parziale rifiuta
     * l'inserimento con una violazione di unicita' (23505) la posizione e'
     * gia' occupata: non si ripiega su una riga di scarto, si torna a mani
     * vuote. E' il database a decidere chi arriva primo, non un controllo
     * applicativo che lascerebbe la finestra fra il guardare e lo scrivere.
     *
     * @param  array<string, mixed>  $candidate
     * @return TrailRegistryCode|null la riga scritta, oppure null se la
     *                                posizione era gia' di un altro.
     */
    protected function writeExistingCodeRow(array $candidate, int $ecTrackId, TrailCodeOrigin $origin): ?TrailRegistryCode
    {
        try {
            return DB::transaction(function () use ($candidate, $ecTrackId, $origin) {
                return TrailRegistryCode::create([
                    ...$candidate,
                    'status' => TrailCodeStatus::Assigned,
                    'origin' => $origin,
                    'ec_track_id' => $ecTrackId,
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Guardia sulle transizioni di stato lecite: e' l'unica documentazione
     * eseguibile del ciclo di vita del codice, e per questo vive in un solo
     * punto invece di essere ripetuta in ciascun metodo. Ogni metodo che
     * cambia stato la chiama prima di scrivere.
     *
     * @param  array<int, TrailCodeStatus>  $allowed
     */
    protected function guardTransition(TrailRegistryCode $code, array $allowed, string $action): void
    {
        if (! in_array($code->status, $allowed, true)) {
            throw InvalidTrailCodeTransitionException::forTransition($code->status, $action, $allowed);
        }
    }

    protected function recordEvent(
        TrailRegistryCode $code,
        ?TrailCodeStatus $from,
        TrailCodeStatus $to,
        string $reason,
        ?int $userId,
    ): void {
        TrailRegistryCodeEvent::create([
            'trail_registry_code_id' => $code->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Il WKT della geometria dell'istanza, letto SENZA passare da Eloquent:
     * la colonna e' `geography`, e propose()/resolveSector() si aspettano un
     * testo accettato da ST_GeomFromText(?, 4326).
     */
    protected function wktOf(TrailApplication $application): string
    {
        $row = DB::selectOne(
            'SELECT ST_AsText(geometry) AS wkt FROM trail_applications WHERE id = ?',
            [$application->id],
        );

        return $row->wkt;
    }

    protected function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = unique_violation in PostgreSQL
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
