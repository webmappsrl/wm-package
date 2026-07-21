<?php

namespace Wm\WmPackage\Services\Models\StoryShare;

/**
 * Layout constants for the Instagram/Facebook Stories share image (oc:8183).
 *
 * SINGLE SOURCE OF TRUTH for every coordinate/size used by {@see StoryShareImageService}
 * to composite the map screenshot + statistics text on top of the app's `story_frame`
 * background. Hardcoded on purpose — not configurable from Nova in this cycle (see
 * plan.md/overview.md "Rischi": uploading a new `story_frame` on Nova only changes the
 * background image, not where the map window/stat blocks sit on top of it). If a future
 * frame redesign moves those elements, THIS is the only file that needs updating.
 *
 * All coordinates are pixels on the final CANVAS_WIDTH x CANVAS_HEIGHT (9:16) canvas.
 */
final class StoryImageLayout
{
    // --- Final canvas (Instagram/Facebook Stories format) ---
    public const CANVAS_WIDTH = 1080;

    public const CANVAS_HEIGHT = 1920;

    // --- Header (logo/branding) reserved band, above the map window ---
    // Shared vertical budget: a dedicated `story_frame` bakes its own logo/badge into this
    // band (see the camminiditalia frame, resources/img/story-frames/), while
    // composeFallback() draws a generic app-icon + app-name header into the same space, so
    // MAP_Y stays a single source of truth regardless of which path produced the background.
    public const HEADER_Y = 90;

    public const HEADER_HEIGHT = 560; // = MAP_Y - HEADER_Y - HEADER_MAP_GAP

    public const HEADER_MAP_GAP = 50;

    // --- Map render window ---
    // The map image (already exactly MAP_WIDTH x MAP_HEIGHT, see MapRenderService) is
    // "cover"-fitted (cropped to fill, never distorted/letterboxed) into this box.
    public const MAP_X = 60;

    public const MAP_Y = self::HEADER_Y + self::HEADER_HEIGHT + self::HEADER_MAP_GAP; // 700

    public const MAP_WIDTH = 960; // = CANVAS_WIDTH - 2 * MAP_X

    public const MAP_HEIGHT = 900;

    // Rounded corners + a thin accent-colored border around the map, so it reads as a
    // deliberately framed "card" rather than a raw screenshot pasted on top of the
    // background (applies to both compose paths, since drawMapCard() wraps the map insert).
    // The border color itself is NOT here: it's resolved per-app at render time from the
    // app's own UI theme color (see StoryShareImageService::resolveAccentColor()), not a
    // fixed constant - a hardcoded hex here would leak one tenant's brand color into every
    // other app's share image.
    public const MAP_CORNER_RADIUS = 28;

    public const MAP_BORDER_WIDTH = 6;

    // --- Statistics block (time / distance / ascent), 3 equal columns below the map ---
    public const STATS_BLOCK_X = self::MAP_X;

    public const STATS_BLOCK_Y = self::MAP_Y + self::MAP_HEIGHT + 40; // 1640

    public const STATS_BLOCK_WIDTH = self::MAP_WIDTH;

    public const STATS_BLOCK_HEIGHT = 220;

    public const STATS_PANEL_CORNER_RADIUS = 24;

    // Backing panel behind the stats, so the text stays legible regardless of the uploaded
    // frame's own colors/design (decision: robustness over relying on every branded frame
    // reserving a pre-designed high-contrast stats area). Deliberately OPAQUE, not
    // semi-transparent: drawRoundedRectFilled() composites 2 rectangles + 4 circles to fake
    // a rounded rect (see its docblock) — with a translucent color, the corner circles would
    // double-blend where they overlap the rectangles, showing up as visibly darker "dots" at
    // each corner (verified empirically). An opaque fill is idempotent under overlap, so this
    // artifact only matters if this constant is ever changed back to an alpha color.
    public const STATS_PANEL_COLOR = '#2a3639';

    // Thin accent-gradient divider drawn along the top edge of the stats panel. Like
    // MAP_BORDER_COLOR above, the actual colors are resolved per-app at render time
    // (StoryShareImageService::resolveAccentColor() + shadeColor()) from the app's own theme
    // color, not hardcoded here.
    public const STATS_ACCENT_HEIGHT = 6;

    public const STATS_PANEL_PADDING = 24;

    public const STATS_VALUE_FONT_SIZE = 52;

    public const STATS_LABEL_FONT_SIZE = 24;

    public const STATS_LABEL_COLOR = '#FFFFFF';

    // Fallback accent color (map border + stats value text + gradient) when the app has no
    // `properties->theme->primary_color` configured in Nova - white reads fine against both
    // the dark FALLBACK_BACKGROUND_COLOR and most uploaded story_frame designs.
    public const DEFAULT_ACCENT_COLOR = '#FFFFFF';

    public const STATS_VALUE_LABEL_GAP = 44; // vertical gap between value and label baselines

    // --- Generic fallback header (composeFallback() only): app icon + app name, used when
    // no dedicated story_frame is uploaded for the app. Deliberately app-agnostic (reads
    // $app->getFirstMedia('icon') + $app->name at render time) - no client-specific asset
    // lives in this shared file. ---
    public const FALLBACK_ICON_SIZE = 160;

    public const FALLBACK_TITLE_FONT_SIZE = 44;

    public const FALLBACK_TITLE_GAP = 28; // gap between icon and app name

    // --- Fonts bundled with the package (resources/fonts/), NOT the host's system fonts:
    // guarantees identical rendering regardless of which server/container this runs on
    // (verified DejaVu Sans is present in the shared dev Docker image, but that is not a
    // safe assumption for every deployment target, so it's vendored here instead). Brand
    // typeface confirmed from camminiditalia.it's own compiled CSS (--font-Montserrat,
    // font-weight-black on headings) - kept as the shared package default (not camminiditalia-
    // specific: Montserrat is a generic, widely-used display face, not a client asset). ---
    public const FONT_BLACK = __DIR__.'/../../../../resources/fonts/Montserrat-Black.ttf';

    public const FONT_BOLD = __DIR__.'/../../../../resources/fonts/Montserrat-Bold.ttf';

    public const FONT_REGULAR = __DIR__.'/../../../../resources/fonts/Montserrat-Regular.ttf';

    // --- Fallback canvas background, used only when the app has no story_frame uploaded.
    // Confirmed as camminiditalia.it's own --color-cm-gray brand value; kept as the shared
    // package default since a dark neutral background suits any outdoor/map app, not just
    // this one. ---
    public const FALLBACK_BACKGROUND_COLOR = '#1d282b';
}
