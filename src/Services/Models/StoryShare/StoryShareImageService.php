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
 * Stateless compositing service for the track-share image (oc:8183) — shareable via any
 * channel (Share.share()), not Instagram Stories specifically.
 *
 * Given an already-rendered map image (produced by {@see MapRenderService} — this service no
 * longer deals with a client-uploaded screenshot at all, see the third revision in
 * docs/features/8183-condivisione-percorso-registrato-sui-social/notes.md) and pre-computed
 * track statistics (time/distance/ascent, from {@see TrackStatsService} — this service has no
 * access to and does not need the UgcTrack model itself), produces a single 1080x1920 (9:16)
 * PNG by compositing:
 *   1. either the app's branded `share_frame` media asset as background, or — if none is
 *      uploaded — a generic dark background with the app's own icon + name as a header
 *      ({@see composeFallback()});
 *   2. the map image, framed as a rounded "card" with a brand-colored border, fitted into a
 *      fixed window on top;
 *   3. the statistics, on a rounded panel with a brand-gradient accent divider.
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
     * @throws RuntimeException if the app's share_frame asset cannot be read.
     */
    public function compose(App $app, InterventionImage $mapImage, array $stats): InterventionImage
    {
        $frameMedia = $app->getFirstMedia('share_frame');

        if ($frameMedia === null) {
            // Fallback decided in plan.md task 7: never produce a broken experience on a
            // freshly configured instance that hasn't uploaded branding yet.
            Log::warning('[oc:8183] share_frame not uploaded for app: falling back to unbranded share image', [
                'app_id' => $app->id,
            ]);

            return $this->composeFallback($app, $mapImage, $stats);
        }

        return $this->composeWithFrame($app, $mapImage, $frameMedia, $stats);
    }

    /**
     * @throws RuntimeException
     */
    private function composeWithFrame(App $app, InterventionImage $screenshot, Media $frameMedia, array $stats): InterventionImage
    {
        try {
            // Disk-agnostic read (works for local AND S3, unlike Media::getPath() which
            // only resolves for local disks): matches how compositing must work identically
            // regardless of the app's storage configuration.
            $frameBytes = Storage::disk($frameMedia->disk)->get($frameMedia->getPathRelativeToRoot());
            $canvas = Image::make($frameBytes)->fit(StoryImageLayout::CANVAS_WIDTH, StoryImageLayout::CANVAS_HEIGHT);
        } catch (Throwable $e) {
            throw new RuntimeException("Unable to read the app's share_frame asset: ".$e->getMessage(), 0, $e);
        }

        $accentColor = $this->resolveAccentColor($app);
        $this->drawMapCard($canvas, $screenshot, $accentColor);
        $this->drawStats($canvas, $stats, $accentColor);

        return $canvas->encode('png');
    }

    /**
     * Generic branded background for apps with no dedicated `share_frame` uploaded: reads
     * $app->getFirstMedia('icon') + $app->name at render time — no client-specific asset lives
     * in this shared file (a dedicated frame, e.g. for camminiditalia, is a Nova-uploaded
     * asset, not code).
     */
    private function composeFallback(App $app, InterventionImage $mapImage, array $stats): InterventionImage
    {
        $canvas = Image::canvas(
            StoryImageLayout::CANVAS_WIDTH,
            StoryImageLayout::CANVAS_HEIGHT,
            StoryImageLayout::FALLBACK_BACKGROUND_COLOR
        );

        $accentColor = $this->resolveAccentColor($app);
        $this->drawFallbackHeader($canvas, $app);
        $this->drawMapCard($canvas, $mapImage, $accentColor);
        $this->drawStats($canvas, $stats, $accentColor);

        return $canvas->encode('png');
    }

    /**
     * Per-app accent color for the map card border + stats value text/gradient — reads the
     * SAME primary color the app's own UI theme uses (Nova `properties->theme->primary_color`,
     * a native Color field also exposed to the frontend as `config.json` -> `THEME.primary`,
     * see AppConfigService::config_section_theme()), so this feature automatically matches
     * whatever brand color each tenant has already configured instead of hardcoding one app's
     * color into shared package code. Falls back to white when unset/malformed, which reads
     * fine against both the dark FALLBACK_BACKGROUND_COLOR and most uploaded share_frame designs.
     */
    private function resolveAccentColor(App $app): string
    {
        $color = $app->properties['theme']['primary_color'] ?? null;

        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }

        return StoryImageLayout::DEFAULT_ACCENT_COLOR;
    }

    /**
     * App icon (if uploaded) + app name, vertically centered in the reserved HEADER band,
     * bottom-aligned just above the map card so it reads as a compact identity line rather
     * than an empty half-filled header.
     */
    private function drawFallbackHeader(InterventionImage $canvas, App $app): void
    {
        $iconMedia = $app->getFirstMedia('icon');
        $iconSize = StoryImageLayout::FALLBACK_ICON_SIZE;
        $centerX = (int) (StoryImageLayout::CANVAS_WIDTH / 2);
        $bottomY = StoryImageLayout::MAP_Y - StoryImageLayout::HEADER_MAP_GAP;

        $titleY = $bottomY - StoryImageLayout::FALLBACK_TITLE_FONT_SIZE;
        $iconY = $titleY - StoryImageLayout::FALLBACK_TITLE_GAP - $iconSize;

        if ($iconMedia !== null) {
            try {
                $iconBytes = Storage::disk($iconMedia->disk)->get($iconMedia->getPathRelativeToRoot());
                $icon = Image::make($iconBytes)->fit($iconSize, $iconSize);
                $this->roundImageCorners($icon, (int) ($iconSize / 2));
                $canvas->insert($icon, 'top-left', $centerX - (int) ($iconSize / 2), $iconY);
            } catch (Throwable $e) {
                Log::warning('[oc:8183] share-story-image: could not read app icon for fallback header', [
                    'app_id' => $app->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $canvas->text($app->name, $centerX, $titleY, function ($font) {
            $font->file(StoryImageLayout::FONT_BLACK);
            $font->size(StoryImageLayout::FALLBACK_TITLE_FONT_SIZE);
            $font->color(StoryImageLayout::STATS_LABEL_COLOR);
            $font->align('center');
            $font->valign('top');
        });
    }

    /**
     * Frames the map as a rounded "card": a solid rounded-rect in the app's accent color
     * (cheap — flat fill, no masking needed) sits behind the map, which is itself inset by
     * MAP_BORDER_WIDTH and corner-cut to the same radius (see {@see roundImageCorners()}) so
     * the border shows as an even ring all the way around.
     */
    private function drawMapCard(InterventionImage $canvas, InterventionImage $screenshot, string $accentColor): void
    {
        $border = StoryImageLayout::MAP_BORDER_WIDTH;

        $this->drawRoundedRectFilled(
            $canvas,
            StoryImageLayout::MAP_X - $border,
            StoryImageLayout::MAP_Y - $border,
            StoryImageLayout::MAP_X + StoryImageLayout::MAP_WIDTH + $border,
            StoryImageLayout::MAP_Y + StoryImageLayout::MAP_HEIGHT + $border,
            StoryImageLayout::MAP_CORNER_RADIUS + $border,
            $accentColor
        );

        $map = clone $screenshot;
        // "cover": crop-to-fill the fixed map window, never distort the map image.
        $map->fit(StoryImageLayout::MAP_WIDTH, StoryImageLayout::MAP_HEIGHT);
        $this->roundImageCorners($map, StoryImageLayout::MAP_CORNER_RADIUS);

        $canvas->insert($map, 'top-left', StoryImageLayout::MAP_X, StoryImageLayout::MAP_Y);
    }

    private function drawStats(InterventionImage $canvas, array $stats, string $accentColor): void
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

        $x1 = StoryImageLayout::STATS_BLOCK_X;
        $y1 = StoryImageLayout::STATS_BLOCK_Y;
        $x2 = $x1 + StoryImageLayout::STATS_BLOCK_WIDTH;
        $y2 = $y1 + StoryImageLayout::STATS_BLOCK_HEIGHT;

        $this->drawRoundedRectFilled($canvas, $x1, $y1, $x2, $y2, StoryImageLayout::STATS_PANEL_CORNER_RADIUS, StoryImageLayout::STATS_PANEL_COLOR);
        // Subtle 2-stop shimmer derived from the single accent color (darker -> the color
        // itself), rather than a fixed 3-color hardcoded gradient - generalizes to any hex,
        // not just camminiditalia's specific sunset-ring palette.
        $this->drawGradientBar($canvas, $x1, $y1, $x2, StoryImageLayout::STATS_ACCENT_HEIGHT, [$this->shadeColor($accentColor, -0.35), $accentColor]);

        $columnWidth = StoryImageLayout::STATS_BLOCK_WIDTH / count($columns);

        foreach ($columns as $index => $column) {
            if ($column['value'] === null) {
                continue;
            }

            $centerX = (int) ($x1 + $columnWidth * $index + $columnWidth / 2);
            $valueY = $y1 + StoryImageLayout::STATS_PANEL_PADDING + StoryImageLayout::STATS_ACCENT_HEIGHT;
            $labelY = $valueY + StoryImageLayout::STATS_VALUE_LABEL_GAP;

            $canvas->text($column['value'], $centerX, $valueY, function ($font) use ($accentColor) {
                $font->file(StoryImageLayout::FONT_BLACK);
                $font->size(StoryImageLayout::STATS_VALUE_FONT_SIZE);
                $font->color($accentColor);
                $font->align('center');
                $font->valign('top');
            });

            $canvas->text($column['label'], $centerX, $labelY, function ($font) {
                $font->file(StoryImageLayout::FONT_BOLD);
                $font->size(StoryImageLayout::STATS_LABEL_FONT_SIZE);
                $font->color(StoryImageLayout::STATS_LABEL_COLOR);
                $font->align('center');
                $font->valign('top');
            });
        }
    }

    /**
     * Solid rounded-rect fill: a flat-color union of a horizontal band, a vertical band, and
     * 4 corner circles. Cheap (a handful of GD primitive calls) because it's a flat fill, not
     * a masked photo — safe to use freely for panels/borders without adding request latency.
     */
    private function drawRoundedRectFilled(InterventionImage $canvas, int $x1, int $y1, int $x2, int $y2, int $radius, string $color): void
    {
        $canvas->rectangle($x1 + $radius, $y1, $x2 - $radius, $y2, function ($draw) use ($color) {
            $draw->background($color);
        });

        $canvas->rectangle($x1, $y1 + $radius, $x2, $y2 - $radius, function ($draw) use ($color) {
            $draw->background($color);
        });

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            $canvas->circle($radius * 2, $cx, $cy, function ($draw) use ($color) {
                $draw->background($color);
            });
        }
    }

    /**
     * Cuts transparent quarter-circle "spandrels" into the 4 corners of a photo image, giving
     * it real rounded corners once composited onto whatever sits behind it. Restricted to the
     * radius x radius corner squares only (not the whole image) — a few thousand pixels total
     * regardless of the image's full size, so it stays fast even on the 960x900 map.
     */
    private function roundImageCorners(InterventionImage $image, int $radius): void
    {
        $core = $image->getCore();
        $width = imagesx($core);
        $height = imagesy($core);

        imagesavealpha($core, true);
        imagealphablending($core, false);
        $transparent = imagecolorallocatealpha($core, 0, 0, 0, 127);

        $corners = [
            [0, 0, $radius, $radius],
            [$width - $radius, 0, $width, $radius],
            [0, $height - $radius, $radius, $height],
            [$width - $radius, $height - $radius, $width, $height],
        ];

        foreach ($corners as [$x1, $y1, $x2, $y2]) {
            $cx = $x1 < $radius ? $radius : $x1;
            $cy = $y1 < $radius ? $radius : $y1;

            for ($y = $y1; $y < $y2; $y++) {
                for ($x = $x1; $x < $x2; $x++) {
                    $dx = $x - $cx;
                    $dy = $y - $cy;

                    if ($dx * $dx + $dy * $dy > $radius * $radius) {
                        imagesetpixel($core, $x, $y, $transparent);
                    }
                }
            }
        }

        imagealphablending($core, true);
    }

    /**
     * Horizontal linear-gradient bar across $colors (equally spaced stops), drawn as a series
     * of 1px-wide filled columns with an interpolated color — same "fake what GD can't do
     * natively" idiom already established by MapRenderService::drawThickLine() for line width.
     */
    private function drawGradientBar(InterventionImage $canvas, int $x1, int $y1, int $x2, int $height, array $colors): void
    {
        $width = $x2 - $x1;
        $segments = count($colors) - 1;

        if ($segments < 1) {
            $canvas->rectangle($x1, $y1, $x2, $y1 + $height, function ($draw) use ($colors) {
                $draw->background($colors[0] ?? '#000000');
            });

            return;
        }

        for ($i = 0; $i < $width; $i++) {
            $t = $i / max(1, $width - 1);
            $segment = min($segments - 1, (int) floor($t * $segments));
            $localT = ($t * $segments) - $segment;

            $color = $this->interpolateColor($colors[$segment], $colors[$segment + 1], $localT);

            $canvas->rectangle($x1 + $i, $y1, $x1 + $i, $y1 + $height, function ($draw) use ($color) {
                $draw->background($color);
            });
        }
    }

    /**
     * Shifts a hex color toward black (negative $amount) or white (positive $amount), by a
     * fraction in [-1, 1]. Used to derive a 2-stop "shimmer" gradient from a single app accent
     * color without needing a designer-picked second stop per app.
     */
    private function shadeColor(string $hex, float $amount): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $target = $amount < 0 ? 0 : 255;
        $t = abs($amount);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r + ($target - $r) * $t),
            (int) round($g + ($target - $g) * $t),
            (int) round($b + ($target - $b) * $t)
        );
    }

    private function interpolateColor(string $from, string $to, float $t): string
    {
        $fromRgb = $this->hexToRgb($from);
        $toRgb = $this->hexToRgb($to);

        $r = (int) round($fromRgb[0] + ($toRgb[0] - $fromRgb[0]) * $t);
        $g = (int) round($fromRgb[1] + ($toRgb[1] - $fromRgb[1]) * $t);
        $b = (int) round($fromRgb[2] + ($toRgb[2] - $fromRgb[2]) * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
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
