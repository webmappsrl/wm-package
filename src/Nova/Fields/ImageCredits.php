<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Fields;

use Laravel\Nova\Fields\Text;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Didascalia, autore e credits di una foto (oc:8959): li scrive la redazione in Nova, sulla foto, e
 * escono con la foto (`MediaResource`, `GeoJsonService`). Stanno nelle custom properties del media,
 * perché la tabella `media` non ha colonne per questi dati.
 *
 * `caption` è la chiave che il package già usava nel GeoJSON e che wm-core mostra sotto la foto
 * (`image-detail`); `author` e `credits` le affiancano. Uno shard che ha un suo campo immagini
 * (forestas) usa gli stessi campi con `fields()`.
 */
final class ImageCredits
{
    public const CAPTION = 'caption';

    public const AUTHOR = 'author';

    public const CREDITS = 'credits';

    /**
     * I campi da passare a `customPropertiesFields()` di un campo `Images`.
     *
     * @return list<Text>
     */
    public static function fields(): array
    {
        return [
            Text::make(__('Caption'), self::CAPTION),
            Text::make(__('Author'), self::AUTHOR),
            Text::make(__('Credits'), self::CREDITS),
        ];
    }

    /**
     * I tre valori di una foto, `null` se mancano o sono vuoti. La didascalia può essere una
     * stringa o un oggetto con le lingue: wm-core la passa a `wmtrans`, che accetta entrambi.
     *
     * @return array{caption: mixed, author: mixed, credits: mixed}
     */
    public static function of(Media $media): array
    {
        $values = [];
        foreach ([self::CAPTION, self::AUTHOR, self::CREDITS] as $key) {
            $value = $media->getCustomProperty($key);
            $values[$key] = $value === '' || $value === [] ? null : $value;
        }

        return $values;
    }
}
