<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Request;
use Wm\WmPackage\Services\Models\MediaService;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mediaService = MediaService::make();
        return [
            'id' => $this->id,
            'name' => $this->custom_properties['name'] ?? ['it' => $this->name],
            'url' => $this->getUrl(),
            'thumbnail' => $mediaService->getThumbnailUrl($this->resource),
            'api_url' => route('default.api.media.geojson', $this->id),
            'sizes' => $mediaService->getSizesUrls($this->resource),
        ];
    }
}
