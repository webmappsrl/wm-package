<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Models\Abstracts\MultiLineString;
use Wm\WmPackage\Models\Abstracts\Point;
use Wm\WmPackage\Models\Abstracts\Polygon;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\Enums\GeometryType;

class FeatureCollectionMap extends Field
{
    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'feature-collection-map';

    /**
     * Flag per abilitare l'arricchimento DEM
     */
    protected bool $demEnrichment = false;

    /**
     * Nome del componente popup personalizzato
     */
    protected ?string $popupComponent = null;

    /**
     * Flag per abilitare lo screenshot della mappa
     */
    protected bool $enableScreenshot = false;

    /**
     * Flag per abilitare lo slope chart (default: true)
     */
    protected bool $enableSlopeChart = true;

    /**
     * Tipi di geometria accettati dal campo.
     *
     * @var GeometryType[]
     */
    protected array $geometryTypes = [GeometryType::MultiLineString];

    /**
     * Indica se geometryTypes è stato impostato esplicitamente (override).
     */
    protected bool $geometryTypesExplicit = false;

    /**
     * Create a new field.
     *
     * @param  string  $name
     * @param  string|callable|null  $attribute
     * @return void
     */
    public function __construct($name, $attribute = null, ?callable $resolveCallback = null)
    {
        parent::__construct($name, $attribute, $resolveCallback);
    }

    /**
     * GeoJSON + centro per compatibilità con form (stesso schema di MapMultiLinestring).
     */
    public function resolve($resource, ?string $attribute = null): void
    {
        $this->applyDetectedGeometryTypes($resource);
        parent::resolve($resource, $attribute);
        $zone = $this->geometryToGeojson($this->value);
        if (! is_null($zone)) {
            $this->withMeta(['geojson' => $zone['geojson']]);
            $this->withMeta(['center' => $zone['center']]);
        }
    }

    public function fillModelWithData(object $model, mixed $value, string $attribute): void
    {
        $this->applyDetectedGeometryTypes($model);
        $newValue = $this->geojsonToGeometry($value);
        $oldAttribute = $this->geometryToGeojson($model->{$attribute});
        if ($oldAttribute) {
            $oldValue = $this->geojsonToGeometry($oldAttribute['geojson']);
        } else {
            $oldValue = null;
        }
        if ($newValue != $oldValue) {
            parent::fillModelWithData($model, $newValue, $attribute);
        }
    }

    /**
     * Deduce i tipi di geometria dal modello (fallback: multilinestring).
     *
     * @return GeometryType[]
     */
    protected function detectGeometryTypes(object $resource): array
    {
        if ($resource instanceof Point) {
            return [GeometryType::Point];
        }
        if ($resource instanceof MultiLineString) {
            return [GeometryType::MultiLineString];
        }
        if ($resource instanceof Polygon) {
            return [GeometryType::MultiPolygon];
        }

        return [GeometryType::MultiLineString];
    }

    /**
     * Applica auto-detect solo se non è stato impostato un override via forGeometryTypes().
     */
    protected function applyDetectedGeometryTypes(object $resource): void
    {
        if ($this->geometryTypesExplicit) {
            return;
        }

        $this->geometryTypes = $this->detectGeometryTypes($resource);

        $this->withMeta([
            'geometryTypes' => array_map(fn (GeometryType $k) => $k->value, $this->geometryTypes),
        ]);
    }

    /**
     * Imposta i tipi di geometria accettati dal campo.
     *
     * @return $this
     */
    public function forGeometryTypes(GeometryType ...$types): static
    {
        $this->geometryTypesExplicit = true;
        $this->geometryTypes = $types ?: [GeometryType::MultiLineString];

        return $this->withMeta([
            'geometryTypes' => array_map(fn (GeometryType $k) => $k->value, $this->geometryTypes),
        ]);
    }

    /**
     * @return array{geojson: string, center: array}|null
     */
    public function geometryToGeojson($geometry)
    {
        $coords = null;
        if (! is_null($geometry)) {
            $g = DB::select("SELECT st_asgeojson('$geometry') as g")[0]->g;
            $c = json_decode(DB::select("SELECT st_asgeojson(ST_Centroid('$geometry')) as g")[0]->g);
            $coords['geojson'] = $g;
            $coords['center'] = [$c->coordinates[1], $c->coordinates[0]];
        }

        return $coords;
    }

    /**
     * Nova può passare la geometria come stringa JSON o come array già decodificato.
     */
    protected function normalizeGeojsonInput(mixed $geojson): ?string
    {
        if ($geojson === null || $geojson === '' || $geojson === 'null') {
            return null;
        }
        if (is_array($geojson) || is_object($geojson)) {
            return json_encode($geojson, JSON_UNESCAPED_UNICODE);
        }

        return trim((string) $geojson);
    }

    public function geojsonToGeometry($geojson)
    {
        $json = $this->normalizeGeojsonInput($geojson);
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true);
            $type = $decoded['type'] ?? null;
            $geometryType = $this->resolveGeometryType($type);

            return match ($geometryType) {
                GeometryType::Point => DB::select(
                    'SELECT ST_AsText(ST_Force2D(ST_GeomFromGeoJSON(?))) AS wkt',
                    [$json]
                )[0]->wkt,
                GeometryType::MultiLineString => DB::select(
                    'SELECT ST_AsText(ST_LineMerge(ST_Force2D(ST_GeomFromGeoJSON(?)))) AS wkt',
                    [$json]
                )[0]->wkt,
                GeometryType::MultiPolygon => DB::select(
                    'SELECT ST_AsText(ST_Force2D(ST_GeomFromGeoJSON(?))) AS wkt',
                    [$json]
                )[0]->wkt,
            };
        } catch (\Throwable $e) {
            \Log::error('FeatureCollectionMap geojsonToGeometry', [
                'message' => $e->getMessage(),
                'geometryTypes' => array_map(fn (GeometryType $k) => $k->value, $this->geometryTypes),
            ]);

            throw $e;
        }
    }

    /**
     * Determina il GeometryType appropriato in base al tipo GeoJSON ricevuto e ai tipi configurati.
     */
    protected function resolveGeometryType(?string $geojsonType): GeometryType
    {
        $geojsonTypeToGeometryType = [
            'Point' => GeometryType::Point,
            'MultiPoint' => GeometryType::Point,
            'LineString' => GeometryType::MultiLineString,
            'MultiLineString' => GeometryType::MultiLineString,
            'Polygon' => GeometryType::MultiPolygon,
            'MultiPolygon' => GeometryType::MultiPolygon,
        ];

        $detectedType = $geojsonTypeToGeometryType[$geojsonType] ?? null;

        if ($detectedType && in_array($detectedType, $this->geometryTypes, true)) {
            return $detectedType;
        }

        return $this->geometryTypes[0];
    }

    /**
     * Abilita l'arricchimento DEM per la FeatureCollection
     * Chiama l'endpoint point-matrix per aggiungere dati di elevazione e distanze
     *
     * @return $this
     */
    public function withDemEnrichment(bool $enabled = true)
    {
        $this->demEnrichment = $enabled;

        return $this->withMeta(['demEnrichment' => $enabled]);
    }

    /**
     * Permette di personalizzare l'URL del GeoJSON
     *
     * @param  string|callable  $url
     * @return $this
     */
    public function geojsonUrl($url)
    {
        return $this->withMeta(['geojsonUrl' => $url]);
    }

    /**
     * Permette di personalizzare l'altezza della mappa
     *
     * @return $this
     */
    public function height(int $height = 500)
    {
        return $this->withMeta(['height' => $height]);
    }

    /**
     * Abilita/disabilita i controlli zoom
     *
     * @return $this
     */
    public function showZoomControls(bool $enabled = true)
    {
        return $this->withMeta(['showZoomControls' => $enabled]);
    }

    /**
     * Abilita/disabilita lo zoom con la rotellina del mouse
     *
     * @return $this
     */
    public function mouseWheelZoom(bool $enabled = true)
    {
        return $this->withMeta(['mouseWheelZoom' => $enabled]);
    }

    /**
     * Abilita/disabilita il pan con il drag
     *
     * @return $this
     */
    public function dragPan(bool $enabled = true)
    {
        return $this->withMeta(['dragPan' => $enabled]);
    }

    /**
     * Imposta il padding per il fit della vista
     *
     * @return $this
     */
    public function padding(int $padding = 50)
    {
        return $this->withMeta(['padding' => $padding]);
    }

    /**
     * Imposta un componente popup personalizzato
     * Il componente deve essere registrato in Nova tramite Nova.booting()
     *
     * @param  string  $componentName  Nome del componente Vue registrato
     * @return $this
     */
    public function withPopupComponent(string $componentName)
    {
        $this->popupComponent = $componentName;

        return $this->withMeta(['popupComponent' => $componentName]);
    }

    /**
     * Abilita la funzionalità di screenshot della mappa usando html2canvas
     *
     * @return $this
     */
    public function enableScreenshot(bool $enabled = true)
    {
        $this->enableScreenshot = $enabled;

        return $this->withMeta(['enableScreenshot' => $enabled]);
    }

    /**
     * Abilita/disabilita lo slope chart sotto la mappa.
     *
     * @return $this
     */
    public function enableSlopeChart(bool $enabled = true)
    {
        $this->enableSlopeChart = $enabled;

        return $this->withMeta(['enableSlopeChart' => $enabled]);
    }

    /**
     * Prepare the field for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'demEnrichment' => $this->demEnrichment,
            'popupComponent' => $this->popupComponent,
            'enableScreenshot' => $this->enableScreenshot,
            'enableSlopeChart' => $this->enableSlopeChart,
            'geometryTypes' => array_map(fn (GeometryType $k) => $k->value, $this->geometryTypes),
        ]);
    }
}
