<?php

namespace Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\Enums;

enum GeometryKind: string
{
    case Point = 'point';
    case MultiLineString = 'multilinestring';
    case MultiPolygon = 'multipolygon';
}
