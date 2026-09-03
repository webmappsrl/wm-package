<?php

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
     * Use before passing a color to hexToRgba(), which throws on any string containing "#"
     * that isn't exactly 6 or 8 hex digits long (e.g. free-text Nova fields, 3-digit CSS
     * shorthand, or any other unvalidated source).
     *
     * Deliberately stricter than the Nova Color field regex and AppConfigService::
     * config_section_theme()'s output filter, both of which tolerate 3-digit hex
     * (^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$) — a valid 3-digit value like "#abc" reaches
     * config.json as-is (CSS accepts 3-digit hex natively), but is treated as invalid
     * here and replaced with $fallback. Do not loosen this to accept 3 digits: every
     * caller of this function feeds its result straight into hexToRgba(), which only
     * accepts exactly 6 or 8 hex digits and throws otherwise (see oc:8367 notes.md,
     * "Review — round 2", for the incident this guards against).
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
