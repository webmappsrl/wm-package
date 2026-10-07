<?php

namespace Wm\WmPackage\Traits;

/**
 * Normalizza related_url in etichetta → url, per l'import Geohub e l'import Excel (oc:8679).
 *
 * Oltre all'oggetto JSON e all'indirizzo semplice recupera i formati presenti sui POI di Geohub:
 * stringa JSON ("https:\/\/…"), lista spezzata in caratteri (["h","t","t","p",…]) e lista
 * WordPress ([{"net7webmap_related_url":"…"}]).
 */
trait NormalizesRelatedUrl
{
    /**
     * @return array<string, string>
     */
    protected function normalizeRelatedUrl(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            // Va prima del ciclo sotto, che tratterebbe ogni carattere come un url.
            if ($this->isSplitCharacterList($value)) {
                return $this->normalizeRelatedUrl(implode('', $value));
            }

            $out = [];
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $wp = $v['net7webmap_related_url'] ?? null;
                    if (is_string($wp) && trim($wp) !== '') {
                        $wp = trim($wp);
                        $out[$wp] = $wp;
                    }

                    continue;
                }
                if (! (is_string($v) || is_numeric($v))) {
                    continue;
                }
                if (is_string($k)) {
                    $out[$k] = (string) $v;
                } else {
                    // Senza etichetta vale solo un indirizzo: scarta i resti di liste spezzate
                    // sporche, come il POI Geohub 39942 (["hhttps://…","t","t","p",…]).
                    $s = trim((string) $v);
                    if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) {
                        $out[$s] = $s;
                    }
                }
            }

            return $out;
        }
        if (! is_string($value)) {
            return [];
        }
        $t = trim($value);
        if ($t === '') {
            return [];
        }
        if (str_starts_with($t, '{') || str_starts_with($t, '[') || str_starts_with($t, '"')) {
            $d = json_decode($t, true);

            return is_array($d) || is_string($d) ? $this->normalizeRelatedUrl($d) : [];
        }
        if (str_starts_with($t, 'http://') || str_starts_with($t, 'https://')) {
            return [$t => $t];
        }

        return [];
    }

    private function isSplitCharacterList(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $v) {
            if (! is_string($v) || mb_strlen($v) !== 1) {
                return false;
            }
        }

        return true;
    }
}
