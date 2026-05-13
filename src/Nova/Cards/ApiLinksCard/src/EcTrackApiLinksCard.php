<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use Wm\WmPackage\Models\EcTrack;

class EcTrackApiLinksCard extends ApiLinksCard
{
    public function __construct(EcTrack $track)
    {
        $shardName = config('wm-package.shard_name', config('app.name'));
        $wmfeUrl = rtrim(config('filesystems.disks.wmfe.url') ?: config('app.url').'/wmfe', '/');

        parent::__construct([
            [
                'label' => 'Track JSON',
                'url' => $wmfeUrl.'/'.$shardName.'/tracks/'.$track->id.'.json',
            ],
        ]);
    }
}
