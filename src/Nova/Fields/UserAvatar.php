<?php

namespace Wm\WmPackage\Nova\Fields;

use Laravel\Nova\Fields\Avatar;
use Laravel\Nova\Fields\Unfillable;
use Laravel\Nova\Nova;

/**
 * @method static static make(\Stringable|string|null $name = null, string $attribute = 'email')
 */
class UserAvatar extends Avatar implements Unfillable
{
    public function __construct($name = null, string $attribute = 'email')
    {
        parent::__construct($name ?? Nova::__('Avatar'), $attribute);

        $this->exceptOnForms()
            ->disableDownload();
    }

    /**
     * Mostra l'avatar reale dell'utente (upload manuale o Gravatar già scaricato al
     * signup, Task 5) se presente; altrimenti calcola al volo lo stesso URL Gravatar
     * live usato da `Laravel\Nova\Fields\Gravatar` (nessuna richiesta di rete qui,
     * solo la formula dell'URL — il browser scarica l'immagine, non il backend).
     *
     * @param  \Laravel\Nova\Resource|\Illuminate\Database\Eloquent\Model|object  $resource
     */
    protected function resolveAttribute($resource, string $attribute): string
    {
        $avatarUrl = $resource->avatar_url ?? null;

        $callback = fn () => $avatarUrl ?: (
            'https://www.gravatar.com/avatar/'.md5(strtolower((string) data_get($resource, $attribute))).'?s=300'
        );

        $this->preview($callback)->thumbnail($callback);

        return $callback();
    }
}
