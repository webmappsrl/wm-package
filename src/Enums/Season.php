<?php

namespace Wm\WmPackage\Enums;

/**
 * Stagione in cui un percorso è preferibilmente percorribile.
 *
 * Vocabolario chiuso a quattro valori, scelto manualmente dall'utente
 * (nessun calcolo automatico). I valori sono identificatori stabili in
 * inglese minuscolo: la resa all'utente passa sempre da label().
 */
enum Season: string
{
    case SPRING = 'spring';
    case SUMMER = 'summer';
    case AUTUMN = 'autumn';
    case WINTER = 'winter';

    /**
     * Etichetta tradotta in una lingua specifica, indipendente dalla locale
     * attiva: serve al consumer per comporre la mappa delle traduzioni da
     * esporre nel config, senza dover duplicare le etichette lato client.
     */
    public function labelIn(string $locale): string
    {
        return match ($this) {
            self::SPRING => __('Spring', [], $locale),
            self::SUMMER => __('Summer', [], $locale),
            self::AUTUMN => __('Autumn', [], $locale),
            self::WINTER => __('Winter', [], $locale),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SPRING => __('Spring'),
            self::SUMMER => __('Summer'),
            self::AUTUMN => __('Autumn'),
            self::WINTER => __('Winter'),
        };
    }

    /**
     * @return array<string, string> value => label, pronto per Multiselect::options()
     */
    public static function toArray(): array
    {
        return array_reduce(self::cases(), function (array $carry, Season $season) {
            $carry[$season->value] = $season->label();

            return $carry;
        }, []);
    }
}
