<?php

namespace Wm\WmPackage\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class TaxonomyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $name = $this->attributes('name')->data['name'];

        return [
            'id' => $this->id,
            'name' => $name,
            'identifier' => $this->identifier,
        ];
    }
}
