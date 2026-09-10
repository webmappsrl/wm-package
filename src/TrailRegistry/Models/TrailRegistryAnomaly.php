<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\TrailCodeParser;

/**
 * Una riga per ogni anomalia rilevata su un sentiero.
 *
 * La tabella si riscrive da zero a ogni esecuzione di
 * TrailRegistryNormalizeCommand: nessuno storico, nessun aggiornamento. Lo
 * storico dei codici vive nel registro (trail_registry_code_events), qui c'e'
 * solo cio' che oggi e' ancora da sistemare.
 *
 * Un sentiero puo' comparire piu' volte: settore discordante e geometria
 * duplicata sono due cose diverse, con due correzioni diverse.
 *
 * Le colonne obbligatorie sono dichiarate nullable di proposito: Nova
 * costruisce i campi anche su un'istanza vuota, per ricavare le colonne
 * dell'elenco prima di avere le righe, e li' non e' valorizzato nulla.
 * Dichiararle non nullable nasconderebbe proprio il caso che ha gia' fatto
 * rispondere 500 alla pagina.
 *
 * @property int|null $id
 * @property int|null $ec_track_id
 * @property TrailRegistryAnomalyType|null $type
 * @property int|null $related_ec_track_id
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 * @property-read EcTrack|null $ecTrack
 * @property-read EcTrack|null $relatedEcTrack
 */
class TrailRegistryAnomaly extends Model
{
    use Concerns\ComposesTrailRegistryMap;

    protected $table = 'trail_registry_anomalies';

    public $timestamps = false;

    protected $fillable = [
        'ec_track_id',
        'type',
        'related_ec_track_id',
        'context',
        'created_at',
    ];

    protected $casts = [
        'type' => TrailRegistryAnomalyType::class,
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function ecTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'ec_track_id');
    }

    public function relatedEcTrack(): BelongsTo
    {
        return $this->belongsTo(EcTrack::class, 'related_ec_track_id');
    }

    /**
     * Cosa mostra la mappa nella scheda dell'anomalia.
     *
     * Sempre: il sentiero rimasto senza numero, in rosso, e i settori che
     * attraversa. Poi cio' che il tipo di anomalia richiede di vedere per
     * capirla — e sono cose diverse:
     *
     * - **codice gia' assegnato**: il sentiero che quel numero lo porta gia',
     *   in verde. Guardandoli insieme si capisce in un colpo se sono due
     *   tratti dello stesso percorso schedati due volte oppure due sentieri
     *   distinti che si contendono la stessa posizione — che e' la domanda a
     *   cui il gestore deve rispondere per decidere quale codice correggere;
     * - **settore discordante**: oltre al settore in cui la traccia ricade
     *   davvero, anche quello che il codice dichiara. Vedere i due poligoni
     *   uno accanto all'altro dice subito se il codice e' sbagliato di poco
     *   (settori confinanti, sentiero al margine) o se e' un errore grosso;
     * - **geometria duplicata**: i gemelli, in linea bianca sottile dentro
     *   quella rossa del soggetto, disegnata piu' larga apposta. Le tracce
     *   coincidono al pixel: due linee dello stesso spessore e colore simile
     *   darebbero una linea sola. Il bianco sul rosso e' il contrasto piu'
     *   netto disponibile, e la differenza di spessore lascia vedere il rosso
     *   ai due lati.
     *
     * @return array<string, mixed>
     */
    public function getFeatureCollectionMap(): array
    {
        $novaPath = $this->novaPath();
        $table = config('wm-package.ec_track_table', 'ec_tracks');

        // I settori per primi: le feature si sovrappongono nell'ordine
        // dell'elenco, e i poligoni devono stare sotto alle linee.
        $sectors = $this->anomalySectorFeatures($novaPath, $table);
        $others = $this->otherTrackFeatures($novaPath, $table);
        $subject = array_filter([$this->subjectTrackFeature($novaPath, $table)]);

        // Chi sta sopra dipende dal caso. Di norma il soggetto va per ultimo,
        // perche' e' lui che deve risaltare. Con i gemelli no: le tracce
        // coincidono al pixel, quindi quella disegnata dopo copre l'altra e
        // si vedrebbe una linea sola proprio dove la mappa deve dire «qui ce
        // ne sono due». Li' il soggetto passa sotto, largo, e i gemelli gli
        // corrono sopra in linea sottile: e' la differenza di spessore a
        // renderli entrambi visibili.
        $features = $this->type === TrailRegistryAnomalyType::GeometriaDuplicata
            ? array_merge($sectors, $subject, $others)
            : array_merge($sectors, $others, $subject);

        return ['type' => 'FeatureCollection', 'features' => array_values($features)];
    }

    /**
     * I settori attraversati dal sentiero, quello prevalente in evidenza.
     *
     * Sul settore discordante si aggiunge anche quello dichiarato dal codice,
     * se esiste: si cerca per `full_code`, tenendo il prefisso ricavato dalla
     * geometria e sostituendo la sola cifra del settore, che e' l'unica cosa
     * che il codice dice diversamente.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function anomalySectorFeatures(string $novaPath, string $table): array
    {
        if ($this->ec_track_id === null) {
            return [];
        }

        $rows = $this->sectorsCrossedBy($table, (int) $this->ec_track_id);

        $features = [];
        $prevalent = true;

        foreach ($rows as $row) {
            $feature = $this->sectorFeature(
                $novaPath,
                (int) $row->id,
                $row->percentuale === null ? null : (float) $row->percentuale,
                $prevalent,
                $prevalent ? __('dalla geometria') : null,
            );

            if ($feature !== null) {
                $features[] = $feature;
            }

            $prevalent = false;
        }

        $declared = $this->declaredSectorFeature($novaPath);

        if ($declared !== null) {
            $features[] = $declared;
        }

        // Il prevalente per ultimo: disegnato per primo finirebbe sotto agli
        // altri e apparirebbe slavato proprio mentre deve risaltare.
        if ($features !== [] && count($features) > 1) {
            $first = array_shift($features);
            $features[] = $first;
        }

        return $features;
    }

    /**
     * Il settore che il codice dichiara, quando non e' quello in cui la
     * traccia ricade. Solo per il settore discordante: altrove non esiste un
     * «settore dichiarato» diverso da quello vero.
     *
     * @return array<string, mixed>|null
     */
    protected function declaredSectorFeature(string $novaPath): ?array
    {
        if ($this->type !== TrailRegistryAnomalyType::SettoreDiscordante) {
            return null;
        }

        $rawCode = $this->context['raw_code'] ?? null;
        $fromGeometry = $this->context['proposed_code'] ?? null;

        if (! is_string($rawCode) || ! is_string($fromGeometry) || strlen($fromGeometry) < 5) {
            return null;
        }

        $digit = TrailCodeParser::sectorDigitFrom($rawCode);

        if ($digit === null) {
            return null;
        }

        $declaredFullCode = substr($fromGeometry, 0, 4).$digit;

        $row = DB::selectOne(
            "SELECT id FROM taxonomy_wheres WHERE properties->>'full_code' = ? LIMIT 1",
            [$declaredFullCode],
        );

        if ($row === null) {
            return null;
        }

        return $this->sectorFeature($novaPath, (int) $row->id, null, false, __('dichiarato dal codice'));
    }

    /**
     * Il sentiero dell'anomalia: rosso e spesso, e' il soggetto della scheda.
     *
     * @return array<string, mixed>|null
     */
    protected function subjectTrackFeature(string $novaPath, string $table): ?array
    {
        if ($this->ec_track_id === null) {
            return null;
        }

        $geometry = $this->geojsonFrom($table, (int) $this->ec_track_id);

        if ($geometry === null) {
            return null;
        }

        $name = $this->context['track']['name'] ?? ('#'.$this->ec_track_id);

        // Piu' larga del solito quando c'e' un gemello sopra: e' lo spazio
        // che lascia intorno alla linea bianca a rendere visibili entrambe.
        $width = $this->type === TrailRegistryAnomalyType::GeometriaDuplicata ? 12 : 7;

        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'tooltip' => (string) $name,
                'strokeColor' => 'rgba(220, 38, 38, 1)',
                'strokeWidth' => $width,
                'link' => url($novaPath.'/resources/'.static::novaUriKey('ec_track').'/'.$this->ec_track_id),
            ],
        ];
    }

    /**
     * Gli altri sentieri in gioco: chi il numero ce l'ha, o i gemelli.
     *
     * I gemelli sono tratteggiati perche' la loro traccia coincide con quella
     * del soggetto: disegnati pieni sparirebbero sotto, e la mappa mostrerebbe
     * una linea sola.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function otherTrackFeatures(string $novaPath, string $table): array
    {
        $targets = match ($this->type) {
            TrailRegistryAnomalyType::CodiceGiaAssegnato => array_filter([$this->context['assigned_to'] ?? null]),
            TrailRegistryAnomalyType::GeometriaDuplicata => $this->context['twins'] ?? [],
            default => [],
        };

        $isTwin = $this->type === TrailRegistryAnomalyType::GeometriaDuplicata;
        $features = [];

        foreach ($targets as $target) {
            if (! is_array($target) || ! isset($target['id'])) {
                continue;
            }

            $geometry = $this->geojsonFrom($table, (int) $target['id']);

            if ($geometry === null) {
                continue;
            }

            $properties = [
                // Il nome, e basta: un ruolo come «traccia duplicata»
                // varrebbe per ogni riga della mappa e non direbbe QUALE
                // sentiero si sta guardando, che e' l'unica cosa che
                // un'etichetta deve dire. Il ruolo lo dice il colore, ed e'
                // scritto nella legenda accanto.
                'tooltip' => (string) ($target['name'] ?? ('#'.$target['id'])),
                'strokeColor' => $isTwin ? 'rgba(255, 255, 255, 1)' : 'rgba(22, 163, 74, 1)',
                'strokeWidth' => 5,
                'link' => url($novaPath.'/resources/'.static::novaUriKey('ec_track').'/'.$target['id']),
            ];

            if ($isTwin) {
                // Bianca e sottile, disegnata dentro la traccia larga del
                // soggetto: restano visibili i due bordi rossi, ed e' quel
                // contorno a dire che sotto c'e' un'altra traccia identica.
                $properties['strokeWidth'] = 4;
            }

            $features[] = ['type' => 'Feature', 'geometry' => $geometry, 'properties' => $properties];
        }

        return $features;
    }
}
