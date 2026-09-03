<?php

if (! defined('THEME_HEX_COLOR_PATTERN')) {
    /**
     * Shared 3-or-6-digit hex color pattern (delimiters included) for App theme colors —
     * single source of truth for Nova\App::themeColorField()'s validation rule and
     * AppConfigService::config_section_theme()'s output filter, which must stay in sync
     * (a value Nova accepts but config_section_theme() rejects, or vice versa, silently
     * breaks the admin-facing color picker). Deliberately looser than sanitizeHexColor()
     * below — see that function's docblock for why the two differ and must not be merged.
     */
    define('THEME_HEX_COLOR_PATTERN', '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/');
}

if (! function_exists('hexToRgba')) {
    /**
     * Convert hex color to rgba color.
     *
     * @param  string  $hexColor
     * @param  float  $opacity
     * @return string
     *
     * @throws Exception
     */
    function hexToRgba($hexColor, $opacity = 1.0)
    {
        if (empty($hexColor)) {
            return '';
        }

        if (strpos($hexColor, '#') === false) {
            return $hexColor;
        }

        $hexColor = ltrim($hexColor, '#');

        if (strlen($hexColor) === 6) {
            [$r, $g, $b] = sscanf($hexColor, '%02x%02x%02x');
        } elseif (strlen($hexColor) === 8) {
            [$r, $g, $b, $a] = sscanf($hexColor, '%02x%02x%02x%02x');
            $opacity = round($a / 255, 2);
        } else {
            throw new Exception('Invalid hex color format.');
        }

        $rgbaColor = "rgba($r, $g, $b, $opacity)";

        return $rgbaColor;
    }
}

if (! function_exists('sanitizeHexColor')) {
    /**
     * Return $value if it's an exact 6-digit hex color (e.g. "#ff0000"), otherwise $fallback.
     *
     * Deliberately stricter than THEME_HEX_COLOR_PATTERN above (used by the Nova Color
     * field and AppConfigService::config_section_theme()'s output filter, both of which
     * tolerate 3-digit hex) — a valid 3-digit value like "#abc" reaches config.json as-is
     * (CSS accepts 3-digit hex natively), but is treated as invalid
     * here and replaced with $fallback. Do not loosen this to accept 3 digits without
     * checking every caller first: AppConfigService::config_section_map() feeds this
     * function's result straight into hexToRgba() (this file), which throws on any
     * string containing "#" that isn't exactly 6 or 8 hex digits long — a 3-digit value
     * reaching it would break the whole App save (see config_section_map()'s own
     * docblock/comments for that incident). StoryShareImageService::resolveAccentColor()
     * is a different kind of caller: its result never reaches hexToRgba(), only a private
     * substr()/hexdec()-based hex parser that never throws but would silently truncate a
     * malformed value into a wrong RGB color instead.
     *
     * @param  mixed  $value
     */
    function sanitizeHexColor($value, string $fallback): string
    {
        if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        return $fallback;
    }
}

if (! function_exists('isReallyEmpty')) {

    function isReallyEmpty($val): bool
    {
        if (is_null($val)) {
            return true;
        }
        if (empty($val)) {
            return true;
        }
        if (is_array($val)) {
            if (count($val) == 0) {
                return true;
            }
            foreach ($val as $lang => $cont) {
                if (! empty($cont)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }
}
