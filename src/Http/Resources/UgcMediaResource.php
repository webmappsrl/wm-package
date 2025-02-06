<?php

namespace Wm\WmPackage\Http\Resources;

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
