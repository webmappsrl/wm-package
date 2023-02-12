<?php

namespace Wm\WmPackage\Exceptions;

use Exception;

class OsmClientException extends Exception
{
}
class OsmClientExceptionInvalidOsmId extends OsmClientException
{ 
}
class OsmClientExceptionNodeHasNoLat extends OsmClientException
{
}
class OsmClientExceptionNodeHasNoLon extends OsmClientException
{
}
class OsmClientExceptionNoElements extends OsmClientException
{
}
class OsmClientExceptionNoTags extends OsmClientException
{
}
class OsmClientExceptionWayHasNoNodes extends OsmClientException
{
}
class OsmClientExceptionRelationHasNoNodes extends OsmClientException
{
}
class OsmClientExceptionRelationHasNoWays extends OsmClientException
{
}
class OsmClientExceptionRelationHasNoRelationElement extends OsmClientException
{
}
class OsmClientExceptionRelationHasNoMembers extends OsmClientException
{
}
class OsmClientExceptionRelationHasInvalidGeometry extends OsmClientException
{
}
