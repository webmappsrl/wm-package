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

    // --- Map screenshot window ---
    // The screenshot (any source aspect ratio, e.g. square from an on-device OpenLayers
    // capture) is "cover"-fitted (cropped to fill, never distorted/letterboxed) into this
    // box when a story_frame is present.
    public const MAP_X = 60;

    public const MAP_Y = 260;

    public const MAP_WIDTH = 960; // = CANVAS_WIDTH - 2 * MAP_X

    public const MAP_HEIGHT = 960; // square window

    // --- Statistics block (time / distance / ascent), 3 equal columns below the map ---
    public const STATS_BLOCK_X = self::MAP_X;

    public const STATS_BLOCK_Y = 1280; // = MAP_Y + MAP_HEIGHT + 60px gap

    public const STATS_BLOCK_WIDTH = self::MAP_WIDTH;

    public const STATS_BLOCK_HEIGHT = 260;

    // Semi-transparent backing panel behind the stats, so the text stays legible
    // regardless of the uploaded frame's own colors/design (decision: robustness over
    // relying on every branded frame reserving a pre-designed high-contrast stats area).
    public const STATS_PANEL_COLOR = 'rgba(0, 0, 0, 0.45)';

    public const STATS_PANEL_PADDING = 20;

    public const STATS_VALUE_FONT_SIZE = 56;

    public const STATS_LABEL_FONT_SIZE = 28;

    public const STATS_TEXT_COLOR = '#FFFFFF';

    public const STATS_VALUE_LABEL_GAP = 46; // vertical gap between value and label baselines

    // --- Fonts bundled with the package (resources/fonts/), NOT the host's system fonts:
    // guarantees identical rendering regardless of which server/container this runs on
    // (verified DejaVu Sans is present in the shared dev Docker image, but that is not a
    // safe assumption for every deployment target, so it's vendored here instead). ---
    public const FONT_BOLD = __DIR__.'/../../../../resources/fonts/DejaVuSans-Bold.ttf';

    public const FONT_REGULAR = __DIR__.'/../../../../resources/fonts/DejaVuSans.ttf';

    // --- Fallback canvas background, used only when the app has no story_frame uploaded ---
    public const FALLBACK_BACKGROUND_COLOR = '#12181f';
}
