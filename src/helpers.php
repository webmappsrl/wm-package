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
