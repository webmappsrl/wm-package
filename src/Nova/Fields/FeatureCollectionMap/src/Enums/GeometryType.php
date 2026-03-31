<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\Enums;

enum GeometryType: string
{
    case Point = 'point';
    case MultiLineString = 'multilinestring';
    case MultiPolygon = 'multipolygon';
}
