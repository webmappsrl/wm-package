<?php

declare(strict_types=1);

namespace Wm\WmPackage\Dto;

/**
 * Standard properties for an EcTrack stored in the `properties` JSONB column.
 *
 * Project-specific imports should extend this class to add custom sub-objects:
 *
 *   readonly class MyTrackPropertiesData extends EcTrackPropertiesData
 *   {
 *       public function __construct(
 *           ?array $description,
 *           ?ManualTrackData $manual_data,
 *           public MyCustomData $custom,
 *       ) {
 *           parent::__construct(description: $description, manual_data: $manual_data);
 *       }
 *
 *       public function toArray(): array
 *       {
 *           return array_merge(parent::toArray(), ['custom' => $this->custom->toArray()]);
 *       }
 *   }
 */
readonly class EcTrackPropertiesData
{
    public function __construct(
        /** @var array<string, string>|null Translatable {it: ..., en: ...} */
        public ?array $description = null,
        /** @var array<string, string>|null Translatable */
        public ?array $excerpt = null,
        public ?ManualTrackData $manual_data = null,
        public ?string $color = null,
        public ?string $cai_scale = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $ref = null,
    ) {}

    /**
     * Serialize to array, omitting null values so existing stored data is not overwritten.
     * ManualTrackData is recursively serialized.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array_filter(
            get_object_vars($this),
            fn ($v) => $v !== null
        );

        if (isset($data['manual_data'])) {
            $data['manual_data'] = $data['manual_data']->toArray();
        }

        return $data;
    }
}
