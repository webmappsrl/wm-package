<?php

namespace Wm\WmPackage\Models;

use Wm\WmPackage\Models\Abstracts\Linestring;
use Wm\WmPackage\Traits\UgcAbleModel;

/**
 * Class UgcTrack
 *
 *
 * @property int    id
 * @property string sku
 * @property string relative_url
 * @property string geometry
 * @property string name
 * @property string description
 * @property string raw_data
 */
class UgcTrack extends Linestring
{
    use UgcAbleModel;
}
