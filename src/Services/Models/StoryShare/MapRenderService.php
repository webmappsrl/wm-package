<?php

namespace Wm\WmPackage\Services\Models\StoryShare;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use RuntimeException;
use Throwable;
use Wm\WmPackage\Enums\AppTiles;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Renders a static raster map image (basemap tiles + the track's own polyline) for a
 * UgcTrack, server-side, with no client involvement (oc:8183, third revision — replaces the
 * previous "client sends a screenshot" flow, dropped due to an unresolved iOS WKWebView
 * CORS/tile-loading issue, see overview.md "Perché").
 *
 * Pipeline:
 *   1. Read the track's PostGIS `geometry` (MultiLineString, geography/SRID 4326) — bbox +
 *      point count via `ST_Extent`/`ST_NPoints`, optionally simplifying with `ST_Simplify`
 *      first if the track has "many" points (see SIMPLIFY_POINT_THRESHOLD below).
 *   2. Pick a zoom level that fits the (padded) bbox inside the target width/height, using
 *      standard Web Mercator slippy-map tile math (same projection as the XYZ tile URLs
 *      themselves, EPSG:3857).
 *   3. Download only the raster tiles that intersect the final fixed-size output window
 *      (bounded regardless of the chosen zoom — see the note on tile count below) from the
 *      same tile server the app itself uses (`App::tiles()`, `sort_order` 0 = base layer;
 *      `AppTiles::webmapp` as a last-resort fallback if the app has no tile configured).
 *   4. Stitch the tiles into one canvas, crop to the exact output window, and draw the
 *      track's own polyline on top, projecting each GPS point to pixel coordinates with the
 *      same Web Mercator formulas used to pick the tiles.
 *
 * Deliberately does NOT encode/return a PNG: returns an unencoded InterventionImage sized
 * exactly `$width x $height`, the same shape {@see StoryShareImageService} previously
 * expected from a client-uploaded screenshot — this keeps that service's `fit()`-into-window
 * compositing code unchanged.
 */
class MapRenderService
{
    private const TILE_SIZE = 256;

    private const MIN_ZOOM = 7;

    private const MAX_ZOOM = 16;

    /**
     * Simplify the geometry with `ST_Simplify` before extracting the polyline coordinates
     * whenever the raw geometry has more than this many points.
     *
     * NOT a tile-count safety measure: the number of tiles fetched is already bounded by the
     * fixed output window size regardless of zoom (a 960x960 window always needs at most
     * ceil(960/256)+1 = 5 tiles per axis, ~25 total, whichever zoom is picked — the chosen
     * zoom only decides how much of the world each tile covers, not how many tiles the
     * window spans). The real cost of an unsimplified geometry is (a) transferring/decoding
     * a possibly huge GeoJSON coordinate array from Postgres for a single request, and (b)
     * looping over it in PHP issuing one `line()`/GD call per segment — each with
     * non-trivial per-call overhead — for visual detail that is imperceptible once
     * compressed into a ~960px-wide image. 300 points is a conservative threshold: a
     * multi-hour hike easily logs several thousand raw GPS fixes, well above what a ~1000px
     * polyline can visually distinguish.
     */
    private const SIMPLIFY_POINT_THRESHOLD = 300;

    /**
     * `ST_Simplify` tolerance in degrees when simplification kicks in, expressed as a
     * fraction of the bbox diagonal (adaptive: a short, dense loop track and a
     * multi-day, cross-region trail should not use the same fixed tolerance), clamped to a
     * sane range. At the equator, 1 degree of latitude is ~111km, so the clamp bounds
     * roughly translate to ~1m (min) .. ~55m (max) of simplification tolerance.
     */
    private const SIMPLIFY_TOLERANCE_MIN_DEGREES = 0.00001;

    private const SIMPLIFY_TOLERANCE_MAX_DEGREES = 0.0005;

    private const SIMPLIFY_TOLERANCE_DIAGONAL_FRACTION = 1 / 1000;

    /**
     * Fraction of the target width/height reserved as empty margin around the track's own
     * bbox, so the polyline never touches the very edge of its map window.
     */
    private const BBOX_MARGIN_RATIO = 0.15;

    /**
     * A degenerate bbox (a track that is a single point, or GPS jitter within a few meters)
     * is expanded to at least this span in degrees before picking a zoom, so the algorithm
     * never tries to zoom in "infinitely" on a point. ~0.0005 degrees is roughly 50m at the
     * equator — a reasonable close-up context window for a near-stationary recording.
     */
    private const MIN_BBOX_SPAN_DEGREES = 0.0005;

    private const TILE_FETCH_TIMEOUT_SECONDS = 5;

    /**
     * Required by some public tile providers' usage policy (e.g. OSM); harmless/ignored by
     * others (e.g. the app's own webmapp tile server) — sent unconditionally rather than
     * special-cased per provider.
     */
    private const USER_AGENT = 'WebmappShareStoryImage/1.0 (+https://webmapp.it)';

    private const TRACK_LINE_COLOR = '#ff5a1f';

    private const TRACK_LINE_THICKNESS_PX = 7;

    /**
     * Background fill for any tile that failed to download — visually distinguishable from
     * a "real" tile without looking like a rendering bug (a neutral, muted color rather
     * than transparent/black, which could look like corruption).
     */
    private const TILE_FALLBACK_COLOR = '#cfcac2';

    /**
     * @throws RuntimeException if the track has no usable geometry, or if every single tile
     *                          needed for the output window fails to download.
     */
    public function render(UgcTrack $ugcTrack, App $app, int $width, int $height): InterventionImage
    {
        $tileUrlTemplate = $this->resolveTileUrlTemplate($app);
        $geometry = $this->extractGeometry($ugcTrack);
        $bbox = $this->padBbox($this->expandDegenerateBbox($geometry['bbox']));
        $zoom = $this->fitZoom($bbox, $width, $height);

        $centerLon = ($bbox['xmin'] + $bbox['xmax']) / 2;
        $centerLat = ($bbox['ymin'] + $bbox['ymax']) / 2;
        $centerPx = $this->lonToPixelX($centerLon, $zoom);
        $centerPy = $this->latToPixelY($centerLat, $zoom);

        $windowLeft = $centerPx - $width / 2;
        $windowTop = $centerPy - $height / 2;

        $canvas = $this->buildTileCanvas($tileUrlTemplate, $zoom, $windowLeft, $windowTop, $width, $height);

        $this->drawTrack($canvas, $geometry['lineStrings'], $zoom, $windowLeft, $windowTop);

        return $canvas;
    }

    /**
     * "The tile server already used by the app": the app's own configured base layer
     * (`app_tile` pivot, `sort_order` 0 = default/base — see AppConfigService.php, which
     * relies on the same ordering to build the `MAP.tiles` config.json entry), falling back
     * to the generic `webmapp` XYZ tiles only if the app has no tile configured at all.
     */
    private function resolveTileUrlTemplate(App $app): string
    {
        $baseTile = $app->tiles()->orderBy('app_tile.sort_order')->first();

        if ($baseTile !== null && ! empty($baseTile->server_xyz)) {
            return $baseTile->server_xyz;
        }

        return AppTiles::webmapp['url'];
    }

    /**
     * @return array{bbox: array{xmin: float, ymin: float, xmax: float, ymax: float}, lineStrings: array<int, array<int, array{0: float, 1: float}>>}
     *
     * @throws RuntimeException
     */
    private function extractGeometry(UgcTrack $ugcTrack): array
    {
        // Two separate queries, not one combined SELECT: ST_Extent is an aggregate function
        // (collapses all matched rows into a single bbox), while ST_NPoints is a plain
        // per-row function — mixing an aggregate and a non-aggregate expression referencing
        // the same column in one SELECT makes Postgres require a GROUP BY ("column
        // ugc_tracks.geometry must appear in the GROUP BY clause"), even though the WHERE
        // clause already guarantees a single row. Same precedent as
        // GeometryComputationService::bbox(), which also only ever selects ST_Extent alone.
        $bboxRow = DB::table('ugc_tracks')
            ->where('id', $ugcTrack->id)
            ->selectRaw('ST_Extent(geometry::geometry) as bbox')
            ->first();

        if ($bboxRow === null || empty($bboxRow->bbox)) {
            throw new RuntimeException("UgcTrack #{$ugcTrack->id} has no usable geometry to render a map from.");
        }

        $bbox = $this->parseBoxString($bboxRow->bbox);

        $npointsRow = DB::table('ugc_tracks')
            ->where('id', $ugcTrack->id)
            ->selectRaw('ST_NPoints(geometry::geometry) as npoints')
            ->first();

        $tolerance = 0.0;

        if ($npointsRow !== null && (int) $npointsRow->npoints > self::SIMPLIFY_POINT_THRESHOLD) {
            $diagonalDegrees = $this->haversineDegreesDiagonal($bbox);
            $tolerance = min(
                self::SIMPLIFY_TOLERANCE_MAX_DEGREES,
                max(self::SIMPLIFY_TOLERANCE_MIN_DEGREES, $diagonalDegrees * self::SIMPLIFY_TOLERANCE_DIAGONAL_FRACTION)
            );
        }

        $geojsonRow = DB::table('ugc_tracks')
            ->where('id', $ugcTrack->id)
            ->selectRaw(
                $tolerance > 0
                    ? 'ST_AsGeoJSON(ST_Simplify(geometry::geometry, ?)) as geom'
                    : 'ST_AsGeoJSON(geometry::geometry) as geom',
                $tolerance > 0 ? [$tolerance] : []
            )
            ->first();

        $decoded = $geojsonRow !== null && ! empty($geojsonRow->geom) ? json_decode($geojsonRow->geom, true) : null;

        if (! is_array($decoded) || ! isset($decoded['coordinates']) || ! is_array($decoded['coordinates'])) {
            throw new RuntimeException("UgcTrack #{$ugcTrack->id} geometry could not be decoded as GeoJSON.");
        }

        // MultiLineString: array of linestrings, each an array of [lon, lat, alt?] tuples.
        // Drop the altitude component here (irrelevant for pixel projection) and keep each
        // linestring separate so drawTrack() never connects the last point of one segment
        // to the first point of the next (e.g. a paused/resumed recording).
        $lineStrings = array_map(
            static fn (array $coordinates) => array_map(
                static fn (array $point) => [(float) $point[0], (float) $point[1]],
                $coordinates
            ),
            $decoded['coordinates']
        );

        return ['bbox' => $bbox, 'lineStrings' => $lineStrings];
    }

    /**
     * Parses a PostGIS `ST_Extent` result, e.g. `BOX(10.123456 44.123456,10.987654 44.987654)`.
     *
     * @return array{xmin: float, ymin: float, xmax: float, ymax: float}
     */
    private function parseBoxString(string $box): array
    {
        $stripped = str_replace(['BOX', '(', ')'], '', $box);
        [$min, $max] = explode(',', $stripped);
        [$xmin, $ymin] = array_map('floatval', preg_split('/\s+/', trim($min)));
        [$xmax, $ymax] = array_map('floatval', preg_split('/\s+/', trim($max)));

        return ['xmin' => $xmin, 'ymin' => $ymin, 'xmax' => $xmax, 'ymax' => $ymax];
    }

    /**
     * Rough degrees-based diagonal of a bbox (no need for true haversine precision here:
     * this only feeds a simplification tolerance heuristic, not the actual rendering).
     *
     * @param  array{xmin: float, ymin: float, xmax: float, ymax: float}  $bbox
     */
    private function haversineDegreesDiagonal(array $bbox): float
    {
        return sqrt(($bbox['xmax'] - $bbox['xmin']) ** 2 + ($bbox['ymax'] - $bbox['ymin']) ** 2);
    }

    /**
     * @param  array{xmin: float, ymin: float, xmax: float, ymax: float}  $bbox
     * @return array{xmin: float, ymin: float, xmax: float, ymax: float}
     */
    private function expandDegenerateBbox(array $bbox): array
    {
        $lonSpan = $bbox['xmax'] - $bbox['xmin'];
        $latSpan = $bbox['ymax'] - $bbox['ymin'];

        if ($lonSpan < self::MIN_BBOX_SPAN_DEGREES) {
            $centerLon = ($bbox['xmin'] + $bbox['xmax']) / 2;
            $bbox['xmin'] = $centerLon - self::MIN_BBOX_SPAN_DEGREES / 2;
            $bbox['xmax'] = $centerLon + self::MIN_BBOX_SPAN_DEGREES / 2;
        }

        if ($latSpan < self::MIN_BBOX_SPAN_DEGREES) {
            $centerLat = ($bbox['ymin'] + $bbox['ymax']) / 2;
            $bbox['ymin'] = $centerLat - self::MIN_BBOX_SPAN_DEGREES / 2;
            $bbox['ymax'] = $centerLat + self::MIN_BBOX_SPAN_DEGREES / 2;
        }

        return $bbox;
    }

    /**
     * @param  array{xmin: float, ymin: float, xmax: float, ymax: float}  $bbox
     * @return array{xmin: float, ymin: float, xmax: float, ymax: float}
     */
    private function padBbox(array $bbox): array
    {
        $lonMargin = ($bbox['xmax'] - $bbox['xmin']) * self::BBOX_MARGIN_RATIO;
        $latMargin = ($bbox['ymax'] - $bbox['ymin']) * self::BBOX_MARGIN_RATIO;

        return [
            'xmin' => $bbox['xmin'] - $lonMargin,
            'ymin' => $bbox['ymin'] - $latMargin,
            'xmax' => $bbox['xmax'] + $lonMargin,
            'ymax' => $bbox['ymax'] + $latMargin,
        ];
    }

    /**
     * Largest zoom (most detail) at which the padded bbox still fits entirely within the
     * target width/height, in Web Mercator pixel space. Bounded to [MIN_ZOOM, MAX_ZOOM] —
     * the tile server doesn't have tiles outside that range — so always terminates, falling
     * back to MIN_ZOOM if even that doesn't fit the target window.
     *
     * @param  array{xmin: float, ymin: float, xmax: float, ymax: float}  $bbox
     */
    private function fitZoom(array $bbox, int $width, int $height): int
    {
        for ($zoom = self::MAX_ZOOM; $zoom > self::MIN_ZOOM; $zoom--) {
            $pixelWidth = $this->lonToPixelX($bbox['xmax'], $zoom) - $this->lonToPixelX($bbox['xmin'], $zoom);
            // North (ymax) maps to a SMALLER pixel-Y than south (ymin) — Web Mercator Y
            // grows downward/southward.
            $pixelHeight = $this->latToPixelY($bbox['ymin'], $zoom) - $this->latToPixelY($bbox['ymax'], $zoom);

            if ($pixelWidth <= $width && $pixelHeight <= $height) {
                return $zoom;
            }
        }

        return self::MIN_ZOOM;
    }

    /**
     * Standard Web Mercator (EPSG:3857) longitude -> world pixel-X at a given zoom, tile
     * size 256 — the same projection XYZ tile URLs are addressed in.
     */
    private function lonToPixelX(float $lon, int $zoom): float
    {
        return ($lon + 180) / 360 * self::TILE_SIZE * (2 ** $zoom);
    }

    /**
     * Standard Web Mercator (EPSG:3857) latitude -> world pixel-Y at a given zoom.
     */
    private function latToPixelY(float $lat, int $zoom): float
    {
        $latRad = deg2rad($lat);

        return (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * self::TILE_SIZE * (2 ** $zoom);
    }

    /**
     * Downloads every tile intersecting the [$windowLeft, $windowTop, +$width, +$height]
     * pixel window at $zoom, stitches them into one canvas, and crops to exactly
     * width x height. A tile that fails to download is filled with TILE_FALLBACK_COLOR
     * rather than aborting the whole render — only a total failure (every tile) throws.
     */
    private function buildTileCanvas(string $tileUrlTemplate, int $zoom, float $windowLeft, float $windowTop, int $width, int $height): InterventionImage
    {
        $n = 2 ** $zoom;
        $tileXMin = (int) floor($windowLeft / self::TILE_SIZE);
        $tileXMax = (int) floor(($windowLeft + $width - 1) / self::TILE_SIZE);
        $tileYMin = (int) floor($windowTop / self::TILE_SIZE);
        $tileYMax = (int) floor(($windowTop + $height - 1) / self::TILE_SIZE);

        $bigWidth = ($tileXMax - $tileXMin + 1) * self::TILE_SIZE;
        $bigHeight = ($tileYMax - $tileYMin + 1) * self::TILE_SIZE;

        $bigCanvas = Image::canvas($bigWidth, $bigHeight, self::TILE_FALLBACK_COLOR);

        $attempted = 0;
        $failed = 0;

        for ($tileY = $tileYMin; $tileY <= $tileYMax; $tileY++) {
            // Latitude does not wrap; a track can never legitimately need a tile outside
            // [0, n-1] here (fitZoom only ever picks a zoom where the bbox itself fits the
            // window), but clamp defensively rather than let an out-of-range Y reach the
            // tile server.
            $clampedY = max(0, min($n - 1, $tileY));

            for ($tileX = $tileXMin; $tileX <= $tileXMax; $tileX++) {
                $attempted++;
                // Longitude wraps around the antimeridian; not a practical concern for this
                // shard's data (Italy-only tracks) but modulo-wrapping is a one-line safety
                // net against a negative/overflowing tile X.
                $wrappedX = (($tileX % $n) + $n) % $n;

                $tileImage = $this->fetchTile($tileUrlTemplate, $zoom, $wrappedX, $clampedY);

                if ($tileImage === null) {
                    $failed++;

                    continue;
                }

                $bigCanvas->insert(
                    $tileImage,
                    'top-left',
                    ($tileX - $tileXMin) * self::TILE_SIZE,
                    ($tileY - $tileYMin) * self::TILE_SIZE
                );
            }
        }

        if ($attempted > 0 && $failed === $attempted) {
            throw new RuntimeException('Unable to download any map tile for the share image (tile server unreachable or returned only errors).');
        }

        if ($failed > 0) {
            Log::warning('[oc:8183] share-story-image: some map tiles failed to download, filled with a placeholder color', [
                'failed' => $failed,
                'attempted' => $attempted,
                'zoom' => $zoom,
            ]);
        }

        $cropX = (int) round($windowLeft - $tileXMin * self::TILE_SIZE);
        $cropY = (int) round($windowTop - $tileYMin * self::TILE_SIZE);

        return $bigCanvas->crop($width, $height, max(0, $cropX), max(0, $cropY));
    }

    private function fetchTile(string $tileUrlTemplate, int $zoom, int $x, int $y): ?InterventionImage
    {
        $url = strtr($tileUrlTemplate, ['{z}' => $zoom, '{x}' => $x, '{y}' => $y]);

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(self::TILE_FETCH_TIMEOUT_SECONDS)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            return Image::make($response->body());
        } catch (Throwable $e) {
            Log::warning('[oc:8183] share-story-image: failed to fetch/decode a map tile', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, array<int, array{0: float, 1: float}>>  $lineStrings
     */
    private function drawTrack(InterventionImage $canvas, array $lineStrings, int $zoom, float $windowLeft, float $windowTop): void
    {
        foreach ($lineStrings as $lineString) {
            $pixels = array_map(
                fn (array $point) => [
                    $this->lonToPixelX($point[0], $zoom) - $windowLeft,
                    $this->latToPixelY($point[1], $zoom) - $windowTop,
                ],
                $lineString
            );

            for ($i = 1, $count = count($pixels); $i < $count; $i++) {
                $this->drawThickLine(
                    $canvas,
                    $pixels[$i - 1][0],
                    $pixels[$i - 1][1],
                    $pixels[$i][0],
                    $pixels[$i][1],
                    self::TRACK_LINE_COLOR,
                    self::TRACK_LINE_THICKNESS_PX
                );
            }
        }
    }

    /**
     * Intervention/GD has no line-width support (`LineShape::width()` throws
     * `NotSupportedException` unconditionally — verified in
     * vendor/intervention/image/src/Intervention/Image/Gd/Shapes/LineShape.php). Fakes a
     * thick stroke by drawing several 1px-wide lines offset along the segment's normal
     * vector, plus a filled circle at the end point so consecutive segments join without a
     * visible gap at direction changes.
     */
    private function drawThickLine(InterventionImage $canvas, float $x1, float $y1, float $x2, float $y2, string $color, int $thickness): void
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $length = sqrt($dx ** 2 + $dy ** 2);

        if ($length < 0.001) {
            $canvas->circle($thickness, (int) round($x1), (int) round($y1), function ($draw) use ($color) {
                $draw->background($color);
            });

            return;
        }

        $normalX = -$dy / $length;
        $normalY = $dx / $length;
        $half = $thickness / 2;

        for ($offset = -$half; $offset <= $half; $offset += 0.75) {
            $offsetX = $normalX * $offset;
            $offsetY = $normalY * $offset;

            $canvas->line(
                (int) round($x1 + $offsetX),
                (int) round($y1 + $offsetY),
                (int) round($x2 + $offsetX),
                (int) round($y2 + $offsetY),
                function ($draw) use ($color) {
                    $draw->color($color);
                }
            );
        }

        $canvas->circle($thickness, (int) round($x2), (int) round($y2), function ($draw) use ($color) {
            $draw->background($color);
        });
    }
}
