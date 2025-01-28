<?php

namespace Wm\WmPackage\Http\Resources;

use Wm\WmPackage\Models\UgcMedia;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UgcMediaResource extends GeometryModelResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {

        return [
            ...parent::toArray($request),
            'relative_url' => $this->relative_url,
        ];
    }
}
