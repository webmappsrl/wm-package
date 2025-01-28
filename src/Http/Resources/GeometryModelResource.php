<?php

namespace Wm\WmPackage\Http\Resources;

use Wm\WmPackage\Models\UgcPoi;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class GeometryModelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $geom = UgcPoi::where('id', '=', $this->id)
            ->select(
                DB::raw('ST_AsGeoJSON(geometry) as geom')
            )
            ->first()
            ->geom;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'app_id' => $this->app_id,
            'name' => $this->name,
            'description' => $this->description,
            'geometry' => json_decode($geom),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
