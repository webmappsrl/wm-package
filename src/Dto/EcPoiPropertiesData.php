<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

/**
 * Standard properties for an EcPoi stored in the `properties` JSONB column.
 *
 * Project-specific imports should extend this class to add custom sub-objects
 * (e.g., a `forestas` key) and override toArray() to include them:
 *
 *   readonly class MyPoiPropertiesData extends EcPoiPropertiesData
 *   {
 *       public function __construct(
 *           ?array $description,
 *           public MyCustomData $custom,
 *       ) {
 *           parent::__construct(description: $description);
 *       }
 *
 *       public function toArray(): array
 *       {
 *           return array_merge(parent::toArray(), ['custom' => $this->custom->toArray()]);
 *       }
 *   }
 */
readonly class EcPoiPropertiesData
{
    public function __construct(
        /** @var array<string, string>|null Translatable {it: ..., en: ...} */
        public ?array $description = null,
        /** @var array<string, string>|null Translatable */
        public ?array $excerpt = null,
        public ?string $out_source_feature_id = null,
        public ?string $addr_complete = null,
        public ?int $capacity = null,
        public ?string $contact_phone = null,
        public ?string $contact_email = null,
        /** @var array<int, string>|null */
        public ?array $related_url = null,
    ) {}

    /**
     * Serialize to array, omitting null values so existing stored data is not overwritten.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            get_object_vars($this),
            fn ($v) => $v !== null
        );
    }
}
