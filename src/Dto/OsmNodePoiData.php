<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

use Wm\WmPackage\Http\Clients\OsmClient;

/**
 * DTO: normalized representation of an OSM node ready to map onto {@see \Wm\WmPackage\Models\EcPoi}.
 *
 * Same pattern as {@see EcPoiPropertiesData}: readonly immutable class with a factory that parses OSM data.
 *
 * OSM tags considered for the (key, value) pair used as "POI type" are listed in {@see self::POI_TYPE_TAG_KEYS},
 * in alphabetical order: the first key present on the node wins.
 */
readonly class OsmNodePoiData
{
    /**
     * OSM tag keys used to derive TaxonomyPoiType.
     * Alphabetical order: first present key (non-empty, not "no") wins.
     *
     * @var list<string>
     */
    public const POI_TYPE_TAG_KEYS = [
        'advertising',
        'aerialway',
        'aeroway',
        'amenity',
        'attraction',
        'barrier',
        'boundary',
        'building',
        'checkpoint',
        'club',
        'craft',
        'emergency',
        'entrance',
        'ford',
        'geological',
        'healthcare',
        'highway',
        'historic',
        'information',
        'landuse',
        'leisure',
        'man_made',
        'military',
        'mountain_pass',
        'natural',
        'office',
        'place',
        'power',
        'public_transport',
        'railway',
        'route',
        'shop',
        'sport',
        'telecom',
        'tourism',
        'traffic_calming',
        'traffic_sign',
        'water',
        'waterway',
    ];

    /**
     * @param  int  $osmid  Numeric OSM node ID (without the "node/" prefix).
     * @param  array<string, string>  $nameTranslations  Locale => name (e.g. ['it' => 'Belvedere', 'en' => 'Viewpoint']).
     * @param  float  $lat  WGS84 latitude.
     * @param  float  $lng  WGS84 longitude.
     * @param  string|null  $poiTypeOsmKey  OSM key chosen as classifier (e.g. "tourism"), null if none.
     * @param  string|null  $poiTypeOsmValue  OSM value chosen (e.g. "viewpoint"), null if none.
     * @param  array<string, string>  $rawTags  Full OSM tags (for audit/debug in properties).
     * @param  string|null  $sourceUpdatedAt  "_updated_at" timestamp from OsmClient.
     */
    public function __construct(
        public int $osmid,
        public array $nameTranslations,
        public float $lat,
        public float $lng,
        public ?string $poiTypeOsmKey,
        public ?string $poiTypeOsmValue,
        public array $rawTags = [],
        public ?string $sourceUpdatedAt = null,
    ) {}

    /**
     * Factory: builds the DTO from {@see OsmClient::getPropertiesAndGeometry()} for an OSM node.
     * Strictly requires a node (GeoJSON Point geometry); ways/relations would need a separate centroid strategy
     * and are out of scope for this importer.
     *
     * @param  array<string, mixed>  $properties  Tags plus technical keys ("_updated_at", …) from OsmClient.
     * @param  array<string, mixed>  $geometry  GeoJSON Point geometry (defensively validated).
     *
     * @throws \InvalidArgumentException When geometry is not a valid Point.
     */
    public static function fromOsmNode(int $osmid, array $properties, array $geometry): self
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        if ($type !== 'Point' || ! is_array($coordinates) || ! isset($coordinates[0], $coordinates[1])) {
            throw new \InvalidArgumentException("OSM node {$osmid}: geometry is not a valid Point.");
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        $tags = [];
        foreach ($properties as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }
            $tags[$key] = is_scalar($value) ? (string) $value : '';
        }

        [$poiKey, $poiValue] = self::pickPoiTypeFromTags($tags);

        return new self(
            osmid: $osmid,
            nameTranslations: self::extractNameTranslations($tags),
            lat: $lat,
            lng: $lng,
            poiTypeOsmKey: $poiKey,
            poiTypeOsmValue: $poiValue,
            rawTags: $tags,
            sourceUpdatedAt: isset($properties['_updated_at']) && is_string($properties['_updated_at'])
                ? $properties['_updated_at']
                : null,
        );
    }

    /**
     * Primary display name (defaults to Italian, then first available locale, then OSM node id).
     */
    public function primaryName(): string
    {
        if (isset($this->nameTranslations['it']) && $this->nameTranslations['it'] !== '') {
            return $this->nameTranslations['it'];
        }
        $firstKey = array_key_first($this->nameTranslations);

        return $firstKey !== null ? $this->nameTranslations[$firstKey] : "OSM node {$this->osmid}";
    }

    /**
     * Composite identifier candidate for TaxonomyPoiType as "key-value" (e.g. "tourism-viewpoint").
     * Returns null when the node has no classifying tag.
     */
    public function poiTypeCompositeIdentifier(): ?string
    {
        if ($this->poiTypeOsmKey === null || $this->poiTypeOsmValue === null) {
            return null;
        }

        return self::normalizeIdentifier("{$this->poiTypeOsmKey}-{$this->poiTypeOsmValue}");
    }

    /**
     * Normalizes an identifier for comparison/persistence: lowercase, trim,
     * spaces/underscores to hyphens, strip non-alphanumeric characters (except "-").
     */
    public static function normalizeIdentifier(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[\s_]+/', '-', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\-]/', '', $value) ?? $value;

        return trim($value, '-');
    }

    /**
     * Attributes ready for {@see \Wm\WmPackage\Models\EcPoi::create()}.
     * `name` stays translatable: set via setTranslation on the caller side.
     *
     * The `properties` block is produced by {@see OsmEcPoiPropertiesData} from OSM tags:
     * maps standard keys (contact_email, contact_phone, opening_hours, addr_*, related_url, …)
     * and keeps raw tags under `properties.osm_data.tags` for audit/debug.
     *
     * @return array<string, mixed>
     */
    public function toEcPoiAttributes(int $appId, ?int $userId = null): array
    {
        $properties = $this->toEcPoiProperties()->toArray();

        $attrs = [
            'osmid' => $this->osmid,
            'app_id' => $appId,
            'geometry' => sprintf('POINT(%F %F)', $this->lng, $this->lat),
            'name' => $this->primaryName(),
            'properties' => $properties,
        ];

        if ($userId !== null) {
            $attrs['user_id'] = $userId;
        }

        return $attrs;
    }

    /**
     * Builds the properties DTO from OSM tags.
     * Resulting shape follows wm-package conventions:
     *  - `properties.osmid` → numeric top-level id (SQL-friendly / EcTrackService)
     *  - `properties.osm_data` → audit block with `type`, `source_updated_at`, `tags`
     */
    public function toEcPoiProperties(): OsmEcPoiPropertiesData
    {
        return OsmEcPoiPropertiesData::fromOsmTags(
            $this->rawTags,
            [
                'osmid' => $this->osmid,
                'type' => 'node',
                'source_updated_at' => $this->sourceUpdatedAt,
                'tags' => $this->rawTags,
            ],
        );
    }

    /**
     * Picks the (key, value) OSM pair used as classifier.
     *
     * @param  array<string, string>  $tags
     * @return array{0: ?string, 1: ?string}
     */
    private static function pickPoiTypeFromTags(array $tags): array
    {
        foreach (self::POI_TYPE_TAG_KEYS as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && $tags[$key] !== 'no') {
                return [$key, $tags[$key]];
            }
        }

        return [null, null];
    }

    /**
     * Extracts name translations from OSM tags (`name`, `name:it`, `name:en`, …).
     * If OSM provides no `name*`, returns an empty array: taxonomy name fallback is the caller's job.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    private static function extractNameTranslations(array $tags): array
    {
        $translations = [];

        if (isset($tags['name:it']) && $tags['name:it'] !== '') {
            $translations['it'] = $tags['name:it'];
        }
        if (isset($tags['name:en']) && $tags['name:en'] !== '') {
            $translations['en'] = $tags['name:en'];
        }

        if (! isset($translations['it']) && isset($tags['name']) && $tags['name'] !== '') {
            $translations['it'] = $tags['name'];
        }

        return $translations;
    }
}
