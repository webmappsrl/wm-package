<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Cards\ApiLinksCard;

use InvalidArgumentException;
use Wm\WmPackage\Enums\WmfeTrackVersion;
use Wm\WmPackage\Models\EcTrack;

class EcTrackApiLinksCard extends ApiLinksCard
{
    public function __construct(EcTrack $track)
    {
        $shardName = config('wm-package.shard_name', config('app.name'));
        $wmfeUrl = rtrim(config('filesystems.disks.wmfe.url') ?: config('app.url').'/wmfe', '/');

        $links = [];

        foreach ($this->activeVersions() as $version) {
            $links[] = [
                // oc:8997: la versione senza numero tiene l'etichetta di sempre, è quella che il
                // team ha in mano; le altre la portano in coda.
                'label' => trim('Track JSON '.$version->pathPrefix(), '/ '),
                'url' => $wmfeUrl.'/'.$shardName.'/tracks/'.$version->pathPrefix().$track->id.'.json',
            ];
        }

        parent::__construct($links);
    }

    /**
     * Le versioni attive, con il ripiego sulla sola versione senza numero.
     *
     * Un nome di versione sconosciuto nel `.env` deve far fallire la **scrittura** del file, non
     * la lettura di un link: questa card sta nella detail view di ogni traccia, e un refuso
     * manderebbe in errore la scheda di ogni sentiero (oc:8997).
     *
     * @return list<WmfeTrackVersion>
     */
    private function activeVersions(): array
    {
        try {
            return WmfeTrackVersion::active();
        } catch (InvalidArgumentException $e) {
            return [WmfeTrackVersion::Legacy];
        }
    }
}
