<?php

namespace Wm\WmPackage\Http\Resources;

use Wm\WmPackage\Models\UgcPoi;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Symm\Gisconverter\Geometry\Geometry;

class UgcPoiResource extends GeometryModelResource {}
