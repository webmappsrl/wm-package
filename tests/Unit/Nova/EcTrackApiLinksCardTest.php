<?php

declare(strict_types=1);

namespace Tests\Unit\Nova;

use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\EcTrackApiLinksCard;
use Wm\WmPackage\Tests\TestCase;

/**
 * oc:8997 — nella detail view della traccia c'è un link per ogni versione attiva del file.
 */
class EcTrackApiLinksCardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'wm-package.shard_name' => 'test-shard',
            'filesystems.disks.wmfe.url' => 'https://example.test/wmfe',
        ]);
    }

    private function track(): EcTrack
    {
        $track = new EcTrack;
        $track->id = 769;

        return $track;
    }

    /** @return array<int, array{label: string, url: string}> */
    private function linksOf(EcTrackApiLinksCard $card): array
    {
        return $card->jsonSerialize()['links'];
    }

    public function test_one_link_per_active_version(): void
    {
        config(['wm-package.wmfe_track_versions' => 'legacy,v1']);

        $links = $this->linksOf(new EcTrackApiLinksCard($this->track()));

        $this->assertSame(['Track JSON', 'Track JSON v1'], array_column($links, 'label'));
        $this->assertSame([
            'https://example.test/wmfe/test-shard/tracks/769.json',
            'https://example.test/wmfe/test-shard/tracks/v1/769.json',
        ], array_column($links, 'url'));
    }

    public function test_only_the_unversioned_link_when_only_legacy_is_active(): void
    {
        config(['wm-package.wmfe_track_versions' => 'legacy']);

        $links = $this->linksOf(new EcTrackApiLinksCard($this->track()));

        $this->assertSame(['Track JSON'], array_column($links, 'label'));
        $this->assertSame('https://example.test/wmfe/test-shard/tracks/769.json', $links[0]['url']);
    }

    public function test_an_unknown_version_does_not_break_the_detail_view(): void
    {
        // Un refuso nel .env fa fallire la scrittura, non la scheda di ogni sentiero.
        config(['wm-package.wmfe_track_versions' => 'legacy,v9']);

        $links = $this->linksOf(new EcTrackApiLinksCard($this->track()));

        $this->assertSame(['Track JSON'], array_column($links, 'label'));
    }
}
