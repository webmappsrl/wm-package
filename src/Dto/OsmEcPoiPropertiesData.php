<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

/**
 * Project-specific DTO extending {@see EcPoiPropertiesData} with properties fields
 * exposed by the Nova `EcPoi` resource but missing on the base DTO
 * (`opening_hours`, `addr_locality`, `addr_housenumber`) plus OSM provenance.
 *
 * Shape follows wm-package conventions (see EcTrackService / HasDemClassification):
 *  - `osmid` at the top level of `properties` (dedicated field, easy SQL access)
 *  - `osm_data` at the top level of `properties`: dict with `type`, `source_updated_at`, `tags`
 *
 * Built only via {@see self::fromOsmTags()}: caller passes normalized OSM tag strings and gets
 * a clean map of only non-null values.
 */
readonly class OsmEcPoiPropertiesData extends EcPoiPropertiesData
{
    /**
     * @param  array<string, string>|null  $description
     * @param  array<string, string>|null  $excerpt
     * @param  array<string, string>|null  $related_url  Label => URL map (e.g. ['website' => 'https://...']); passed to parent for standard serialization.
     * @param  array<string, mixed>|null  $osm_data  Audit block aligned with wm-package conventions.
     * @param  int|null  $osmid  Numeric OSM node id, stored top-level for SQL compatibility.
     */
    public function __construct(
        ?array $description = null,
        ?array $excerpt = null,
        ?string $out_source_feature_id = null,
        ?string $addr_complete = null,
        ?int $capacity = null,
        ?string $contact_phone = null,
        ?string $contact_email = null,
        ?array $related_url = null,
        public ?string $opening_hours = null,
        public ?string $addr_locality = null,
        public ?string $addr_housenumber = null,
        public ?array $osm_data = null,
        public ?int $osmid = null,
    ) {
        parent::__construct(
            description: $description,
            excerpt: $excerpt,
            out_source_feature_id: $out_source_feature_id,
            addr_complete: $addr_complete,
            capacity: $capacity,
            contact_phone: $contact_phone,
            contact_email: $contact_email,
            related_url: $related_url,
        );
    }

    /**
     * Factory from OSM tags (already normalized to strings).
     *
     * @param  array<string, string>  $tags
     * @param  array<string, mixed>  $audit  Must include `osmid` (int), `type` (string), `source_updated_at` (string|null), `tags` (array).
     */
    public static function fromOsmTags(array $tags, array $audit = []): self
    {
        $capacity = self::firstNonEmpty($tags, ['capacity']);
        $capacityInt = $capacity !== null && ctype_digit(trim($capacity)) ? (int) $capacity : null;

        $osmid = isset($audit['osmid']) && is_int($audit['osmid']) ? $audit['osmid'] : null;

        $osmData = $audit !== [] ? array_filter([
            'type' => $audit['type'] ?? null,
            'source_updated_at' => $audit['source_updated_at'] ?? null,
            'tags' => $audit['tags'] ?? null,
        ], static fn ($v) => $v !== null) : null;

        return new self(
            description: self::extractTranslated($tags, 'description'),
            excerpt: self::extractTranslated($tags, 'inscription'),
            out_source_feature_id: isset($tags['ref']) && $tags['ref'] !== '' ? $tags['ref'] : null,
            addr_complete: self::buildCompleteAddress($tags),
            capacity: $capacityInt,
            contact_phone: self::firstNonEmpty($tags, ['contact:phone', 'phone']),
            contact_email: self::firstNonEmpty($tags, ['contact:email', 'email']),
            related_url: self::extractRelatedUrls($tags),
            opening_hours: self::firstNonEmpty($tags, ['opening_hours']),
            addr_locality: self::firstNonEmpty($tags, ['addr:city']),
            addr_housenumber: self::firstNonEmpty($tags, ['addr:housenumber']),
            osm_data: $osmData !== [] ? $osmData : null,
            osmid: $osmid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $extra = array_filter([
            'osmid' => $this->osmid,
            'opening_hours' => $this->opening_hours,
            'addr_locality' => $this->addr_locality,
            'addr_housenumber' => $this->addr_housenumber,
            'osm_data' => $this->osm_data,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return array_merge(parent::toArray(), $extra);
    }

    /**
     * @param  array<string, string>  $tags
     * @param  list<string>  $keys
     */
    private static function firstNonEmpty(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($tags[$key]) && trim($tags[$key]) !== '') {
                return trim($tags[$key]);
            }
        }

        return null;
    }

    /**
     * Combines addr:street, addr:housenumber, addr:postcode, addr:city into one string
     * such as "Via Example 12, 41023 Lama Mocogno". Returns null when nothing meaningful is present.
     *
     * @param  array<string, string>  $tags
     */
    private static function buildCompleteAddress(array $tags): ?string
    {
        $street = self::firstNonEmpty($tags, ['addr:street']);
        $housenumber = self::firstNonEmpty($tags, ['addr:housenumber']);
        $postcode = self::firstNonEmpty($tags, ['addr:postcode']);
        $city = self::firstNonEmpty($tags, ['addr:city']);

        $streetPart = trim(($street ?? '').' '.($housenumber ?? ''));
        $cityPart = trim(($postcode ?? '').' '.($city ?? ''));

        $parts = array_values(array_filter([$streetPart, $cityPart], static fn ($p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        return implode(', ', $parts);
    }

    /**
     * Extracts external links as a label => URL map compatible with Nova KeyValue.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>|null
     */
    private static function extractRelatedUrls(array $tags): ?array
    {
        $out = [];

        foreach (['website', 'contact:website', 'url'] as $key) {
            if (isset($tags[$key]) && $tags[$key] !== '' && self::isHttpUrl($tags[$key])) {
                $out['website'] = $tags[$key];
                break;
            }
        }

        if (isset($tags['wikipedia']) && $tags['wikipedia'] !== '') {
            $out['wikipedia'] = self::wikipediaUrl($tags['wikipedia']);
        }
        if (isset($tags['wikidata']) && preg_match('/^Q\d+$/', $tags['wikidata']) === 1) {
            $out['wikidata'] = 'https://www.wikidata.org/wiki/'.$tags['wikidata'];
        }

        return $out === [] ? null : $out;
    }

    private static function isHttpUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * Turns "en:Title" or full URLs into a canonical Wikipedia URL.
     */
    private static function wikipediaUrl(string $value): string
    {
        if (self::isHttpUrl($value)) {
            return $value;
        }
        if (preg_match('/^([a-z]{2,3}):(.+)$/', $value, $m) === 1) {
            return "https://{$m[1]}.wikipedia.org/wiki/".rawurlencode(str_replace(' ', '_', $m[2]));
        }

        return 'https://en.wikipedia.org/wiki/'.rawurlencode(str_replace(' ', '_', $value));
    }

    /**
     * Extracts translations for `description` / `excerpt` with fallback to the base tag.
     *
     * @param  array<string, string>  $tags
     * @return array<string, string>|null
     */
    private static function extractTranslated(array $tags, string $base): ?array
    {
        $out = [];

        if (isset($tags["{$base}:it"]) && $tags["{$base}:it"] !== '') {
            $out['it'] = $tags["{$base}:it"];
        }
        if (isset($tags["{$base}:en"]) && $tags["{$base}:en"] !== '') {
            $out['en'] = $tags["{$base}:en"];
        }
        if (! isset($out['it']) && isset($tags[$base]) && $tags[$base] !== '') {
            $out['it'] = $tags[$base];
        }

        return $out === [] ? null : $out;
    }
}
