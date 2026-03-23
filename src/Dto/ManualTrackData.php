<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

/**
 * Represents the `manual_data` sub-object inside an EcTrack properties column.
 *
 * Manual data contains values that were explicitly set by a user or an import source,
 * taking precedence over auto-computed DEM/OSM values on subsequent updates.
 * Only non-null values are serialized, so existing manual data not covered by this
 * DTO instance is preserved when merging with array_merge.
 */
readonly class ManualTrackData
{
    public function __construct(
        public ?string $distance = null,
        public ?string $ascent = null,
        public ?string $descent = null,
        public ?string $duration_forward = null,
        public ?string $duration_backward = null,
        public ?string $ele_from = null,
        public ?string $ele_to = null,
        public ?string $ele_min = null,
        public ?string $ele_max = null,
    ) {}

    /**
     * Build from a raw array (e.g., existing properties['manual_data']).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            distance: isset($data['distance']) ? (string) $data['distance'] : null,
            ascent: isset($data['ascent']) ? (string) $data['ascent'] : null,
            descent: isset($data['descent']) ? (string) $data['descent'] : null,
            duration_forward: isset($data['duration_forward']) ? (string) $data['duration_forward'] : null,
            duration_backward: isset($data['duration_backward']) ? (string) $data['duration_backward'] : null,
            ele_from: isset($data['ele_from']) ? (string) $data['ele_from'] : null,
            ele_to: isset($data['ele_to']) ? (string) $data['ele_to'] : null,
            ele_min: isset($data['ele_min']) ? (string) $data['ele_min'] : null,
            ele_max: isset($data['ele_max']) ? (string) $data['ele_max'] : null,
        );
    }

    /**
     * Merge two ManualTrackData instances, with $override taking precedence for non-null values.
     */
    public static function merge(self $base, self $override): self
    {
        return new self(
            distance: $override->distance ?? $base->distance,
            ascent: $override->ascent ?? $base->ascent,
            descent: $override->descent ?? $base->descent,
            duration_forward: $override->duration_forward ?? $base->duration_forward,
            duration_backward: $override->duration_backward ?? $base->duration_backward,
            ele_from: $override->ele_from ?? $base->ele_from,
            ele_to: $override->ele_to ?? $base->ele_to,
            ele_min: $override->ele_min ?? $base->ele_min,
            ele_max: $override->ele_max ?? $base->ele_max,
        );
    }

    /**
     * Serialize to array, omitting null values so existing stored data is not overwritten.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter(
            get_object_vars($this),
            fn ($v) => $v !== null
        );
    }
}
