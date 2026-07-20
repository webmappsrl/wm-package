<?php

namespace Wm\WmPackage\Services\Models\StoryShare;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Intervention\Image\Image as InterventionImage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Wm\WmPackage\Models\App;

/**
 * Stateless compositing service for the Instagram/Facebook Stories share image (oc:8183).
 *
 * Given an already-rendered map image (produced by {@see MapRenderService} — this service no
 * longer deals with a client-uploaded screenshot at all, see the third revision in
 * docs/features/8183-condivisione-percorso-registrato-sui-social/notes.md) and pre-computed
 * track statistics (time/distance/ascent, from {@see TrackStatsService} — this service has no
 * access to and does not need the UgcTrack model itself), produces a single 1080x1920 (9:16)
 * PNG by compositing:
 *   1. the app's branded `story_frame` media asset as background,
 *   2. the map image fitted into a fixed window on top of it,
 *   3. the statistics as text in a fixed position.
 *
 * No persistence here: the caller (ShareStoryImageController) is responsible for persisting
 * the returned image to the UgcTrack's `share_image` media collection. See
 * {@see StoryImageLayout} for every hardcoded coordinate/size used here.
 */
class StoryShareImageService
{
    /**
     * @param  array{duration_seconds?: int|null, distance_km?: float|null, ascent_meters?: float|null}  $stats
     *
     * @throws RuntimeException if the app's story_frame asset cannot be read.
     */
    public function compose(App $app, InterventionImage $mapImage, array $stats): InterventionImage
    {
        $frameMedia = $app->getFirstMedia('story_frame');

        if ($frameMedia === null) {
            // Fallback decided in plan.md task 7: never produce a broken experience on a
            // freshly configured instance that hasn't uploaded branding yet.
            Log::warning('[oc:8183] story_frame not uploaded for app: falling back to unbranded story share image', [
                'app_id' => $app->id,
            ]);

            return $this->composeFallback($mapImage, $stats);
        }

        return $this->composeWithFrame($mapImage, $frameMedia, $stats);
    }

    /**
     * @throws RuntimeException
     */
    private function composeWithFrame(InterventionImage $screenshot, Media $frameMedia, array $stats): InterventionImage
    {
        try {
            // Disk-agnostic read (works for local AND S3, unlike Media::getPath() which
            // only resolves for local disks): matches how compositing must work identically
            // regardless of the app's storage configuration.
            $frameBytes = Storage::disk($frameMedia->disk)->get($frameMedia->getPathRelativeToRoot());
            $canvas = Image::make($frameBytes)->fit(StoryImageLayout::CANVAS_WIDTH, StoryImageLayout::CANVAS_HEIGHT);
        } catch (Throwable $e) {
            throw new RuntimeException("Unable to read the app's story_frame asset: ".$e->getMessage(), 0, $e);
        }

        $map = clone $screenshot;
        // "cover": crop-to-fill the fixed map window, never distort the screenshot.
        $map->fit(StoryImageLayout::MAP_WIDTH, StoryImageLayout::MAP_HEIGHT);

        $canvas->insert($map, 'top-left', StoryImageLayout::MAP_X, StoryImageLayout::MAP_Y);

        $this->drawStats($canvas, $stats);

        return $canvas->encode('png');
    }

    private function composeFallback(InterventionImage $screenshot, array $stats): InterventionImage
    {
        $canvas = Image::canvas(
            StoryImageLayout::CANVAS_WIDTH,
            StoryImageLayout::CANVAS_HEIGHT,
            StoryImageLayout::FALLBACK_BACKGROUND_COLOR
        );

        // "contain" (aspectRatio + upsize guard), NOT "cover": with no branding to justify a
        // fixed crop window, the user's own screenshot must never be cropped (plan.md task 7:
        // "ritornare lo screenshot grezzo centrato/paddato a 9:16 senza frame").
        $padded = clone $screenshot;
        $padded->resize(StoryImageLayout::CANVAS_WIDTH, StoryImageLayout::CANVAS_HEIGHT, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $canvas->insert($padded, 'center');

        $this->drawStats($canvas, $stats);

        return $canvas->encode('png');
    }

    private function drawStats(InterventionImage $canvas, array $stats): void
    {
        $columns = [
            ['value' => $this->formatDuration($stats['duration_seconds'] ?? null), 'label' => 'TEMPO'],
            ['value' => $this->formatDistance($stats['distance_km'] ?? null), 'label' => 'DISTANZA'],
            ['value' => $this->formatAscent($stats['ascent_meters'] ?? null), 'label' => 'DISLIVELLO'],
        ];

        // No stats at all (e.g. not provided) — draw nothing rather than an empty panel.
        if (! array_filter($columns, fn ($column) => $column['value'] !== null)) {
            return;
        }

        $columnWidth = StoryImageLayout::STATS_BLOCK_WIDTH / count($columns);

        $canvas->rectangle(
            StoryImageLayout::STATS_BLOCK_X,
            StoryImageLayout::STATS_BLOCK_Y,
            StoryImageLayout::STATS_BLOCK_X + StoryImageLayout::STATS_BLOCK_WIDTH,
            StoryImageLayout::STATS_BLOCK_Y + StoryImageLayout::STATS_BLOCK_HEIGHT,
            function ($draw) {
                $draw->background(StoryImageLayout::STATS_PANEL_COLOR);
            }
        );

        foreach ($columns as $index => $column) {
            if ($column['value'] === null) {
                continue;
            }

            $centerX = (int) (StoryImageLayout::STATS_BLOCK_X + $columnWidth * $index + $columnWidth / 2);
            $valueY = StoryImageLayout::STATS_BLOCK_Y + StoryImageLayout::STATS_PANEL_PADDING + StoryImageLayout::STATS_VALUE_FONT_SIZE;
            $labelY = $valueY + StoryImageLayout::STATS_VALUE_LABEL_GAP;

            $canvas->text($column['value'], $centerX, $valueY, function ($font) {
                $font->file(StoryImageLayout::FONT_BOLD);
                $font->size(StoryImageLayout::STATS_VALUE_FONT_SIZE);
                $font->color(StoryImageLayout::STATS_TEXT_COLOR);
                $font->align('center');
                $font->valign('top');
            });

            $canvas->text($column['label'], $centerX, $labelY, function ($font) {
                $font->file(StoryImageLayout::FONT_REGULAR);
                $font->size(StoryImageLayout::STATS_LABEL_FONT_SIZE);
                $font->color(StoryImageLayout::STATS_TEXT_COLOR);
                $font->align('center');
                $font->valign('top');
            });
        }
    }

    private function formatDuration(int|float|null $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $seconds = (int) $seconds;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? sprintf('%dh %02dm', $hours, $minutes) : sprintf('%dm', $minutes);
    }

    private function formatDistance(int|float|null $km): ?string
    {
        if ($km === null) {
            return null;
        }

        return number_format((float) $km, 1, ',', '').' km';
    }

    private function formatAscent(int|float|null $meters): ?string
    {
        if ($meters === null) {
            return null;
        }

        return '+'.number_format((float) $meters, 0, ',', '').' m';
    }
}
