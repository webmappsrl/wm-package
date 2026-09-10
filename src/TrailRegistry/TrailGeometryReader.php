<?php

namespace Wm\WmPackage\TrailRegistry;

use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailGeometryException;

/**
 * Legge il tracciato di un'istanza da un file caricato e lo restituisce come
 * WKT `MULTILINESTRING Z`.
 *
 * Il tipo di ritorno non e' una scelta di comodo: `trail_applications.geometry`
 * e' una `geography(MultiLineStringZ, 4326)` allineata a `ec_tracks.geometry`
 * perche' all'approvazione la geometria viene copiata sul sentiero con un
 * UPDATE diretto (vedi la migrazione delle istanze). Un LineString o una
 * geometria 2D farebbero fallire l'insert, o perderebbero la quota.
 *
 * Il WKT si compone in PHP e non in PostGIS di proposito: la quota di un GPX
 * sta negli attributi `<ele>`, non nelle coordinate, quindi un giro per
 * `ST_GeomFromGeoJSON` la perderebbe comunque — e senza interpolare stringhe
 * di file caricati dentro una query.
 */
class TrailGeometryReader
{
    /**
     * @throws InvalidTrailGeometryException
     */
    public function wktFrom(string $fileContent): string
    {
        $content = trim($fileContent);

        if ($content === '') {
            throw InvalidTrailGeometryException::unrecognizedFormat();
        }

        $segments = match ($content[0]) {
            '<' => $this->segmentsFromGpx($content),
            '{', '[' => $this->segmentsFromGeojson($content),
            default => throw InvalidTrailGeometryException::unrecognizedFormat(),
        };

        if ($segments === []) {
            throw InvalidTrailGeometryException::noUsableLine();
        }

        return 'MULTILINESTRING Z ('.implode(', ', $segments).')';
    }

    /**
     * @return array<int, string>
     */
    protected function segmentsFromGpx(string $content): array
    {
        // Il namespace di default va rimosso perche' SimpleXML, altrimenti,
        // non attraversa gli elementi senza prefisso.
        $content = preg_replace('/xmlns\s*=\s*"[^"]*"/', '', $content, 1) ?? $content;

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw InvalidTrailGeometryException::invalidXml();
        }

        $segments = [];

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $segment) {
                $segments[] = $this->segmentFromGpxPoints($segment->trkpt);
            }
        }

        // Alcune sorgenti pubblicano l'itinerario come rotta
        // (`<rte>`/`<rtept>`) invece che come traccia: tipicamente GPX nati da
        // una conversione da KML o da una digitalizzazione in QGIS. Senza
        // questo ramo il file sembra vuoto pur avendo la geometria.
        foreach ($xml->rte as $route) {
            $segments[] = $this->segmentFromGpxPoints($route->rtept);
        }

        return array_values(array_filter($segments));
    }

    /**
     * Un segmento richiede almeno due punti: uno solo produrrebbe una
     * LINESTRING degenere, che PostGIS rifiuta all'insert.
     */
    protected function segmentFromGpxPoints(\SimpleXMLElement $points): ?string
    {
        $coordinates = [];

        foreach ($points as $point) {
            $coordinates[] = sprintf(
                '%s %s %s',
                $this->number((float) $point['lon']),
                $this->number((float) $point['lat']),
                $this->number(isset($point->ele) ? (float) $point->ele : 0.0),
            );
        }

        return count($coordinates) >= 2
            ? '('.implode(', ', $coordinates).')'
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected function segmentsFromGeojson(string $content): array
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw InvalidTrailGeometryException::invalidJson();
        }

        $segments = [];

        foreach ($this->geometriesOf($decoded) as $geometry) {
            $type = $geometry['type'] ?? null;
            $coordinates = $geometry['coordinates'] ?? null;

            if (! is_array($coordinates)) {
                continue;
            }

            $lines = match ($type) {
                'LineString' => [$coordinates],
                'MultiLineString' => $coordinates,
                default => [],
            };

            foreach ($lines as $line) {
                $segment = $this->segmentFromGeojsonLine($line);

                if ($segment !== null) {
                    $segments[] = $segment;
                }
            }
        }

        return $segments;
    }

    /**
     * Le geometrie contenute in un GeoJSON, qualunque involucro abbia: una
     * FeatureCollection, una singola Feature o una geometria nuda.
     *
     * @param  array<mixed>  $decoded
     * @return array<int, array<mixed>>
     */
    protected function geometriesOf(array $decoded): array
    {
        if (($decoded['type'] ?? null) === 'FeatureCollection') {
            $geometries = [];

            foreach ($decoded['features'] ?? [] as $feature) {
                if (is_array($feature) && is_array($feature['geometry'] ?? null)) {
                    $geometries[] = $feature['geometry'];
                }
            }

            return $geometries;
        }

        if (($decoded['type'] ?? null) === 'Feature') {
            return is_array($decoded['geometry'] ?? null) ? [$decoded['geometry']] : [];
        }

        return [$decoded];
    }

    /**
     * @param  array<mixed>  $line
     */
    protected function segmentFromGeojsonLine(array $line): ?string
    {
        $coordinates = [];

        foreach ($line as $position) {
            if (! is_array($position) || count($position) < 2) {
                continue;
            }

            $coordinates[] = sprintf(
                '%s %s %s',
                $this->number((float) $position[0]),
                $this->number((float) $position[1]),
                $this->number(isset($position[2]) ? (float) $position[2] : 0.0),
            );
        }

        return count($coordinates) >= 2
            ? '('.implode(', ', $coordinates).')'
            : null;
    }

    /**
     * Un numero come lo scriverebbe PostGIS: senza zeri decimali inutili
     * (`1`, non `1.0000000`) e senza notazione esponenziale, che il parser WKT
     * non accetta.
     */
    protected function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.') ?: '0';
    }
}
