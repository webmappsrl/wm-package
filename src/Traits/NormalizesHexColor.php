<?php

namespace Wm\WmPackage\Traits;

trait NormalizesHexColor
{
    protected function normalizeHexColor(mixed $hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }

        $hex = trim($hex);

        if ($hex === '') {
            return null;
        }

        if (! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        $hex = strtoupper($hex);

        if (strlen($hex) === 4) {
            $hex = sprintf(
                '#%s%s%s%s%s%s',
                $hex[1],
                $hex[1],
                $hex[2],
                $hex[2],
                $hex[3],
                $hex[3]
            );
        }

        if (strlen($hex) > 7) {
            $hex = substr($hex, 0, 7);
        }

        return $hex;
    }
}

