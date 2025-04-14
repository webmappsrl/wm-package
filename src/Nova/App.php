<?php

namespace Wm\WmPackage\Nova;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Laravel\Nova\Tabs\Tab;
use Marshmallow\Tiptap\Tiptap;
use Whitecube\NovaFlexibleContent\Flexible;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;

use Wm\WmPackage\Nova\Actions\UpdateTracksOnAws;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Services\Models\MediaService;

class App extends Resource
{
    public static $model = \Wm\WmPackage\Models\App::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable(),
            Tab::group('App', [
                Tab::make('home', $this->home_tab())
            ]),

            // TODO: implement fields
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            UpdateTracksOnAws::make()
                ->onlyOnDetail()
                ->confirmText('Sei sicuro di voler aggiornare tutte le tracks di questa app su AWS?')
                ->confirmButtonText('Sì, aggiorna')
                ->cancelButtonText('No, annulla'),
        ];
    }

    protected function home_tab(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make('Welcome', 'welcome')
                    ->buttons([
                        'heading',
                        '|',
                        'bold',
                        'italic',
                        'underline',
                        '|',
                        'bulletList',
                        'orderedList',
                        '|',
                        'link',
                        'image',
                        '|',
                        'textAlign',
                        '|',
                        'horizontalRule',
                    ])
                    ->headingLevels([2, 3, 4]),
            ]),
            Flexible::make('config_home')
                ->resolver(ConfigHomeResolver::class)
                ->menu('flexible-search-menu')
                ->button('Aggiungi contenuto')
                ->help("Configurazione della home page dell'app")
                ->addLayout('Titolo', 'title', $this->title_layout())
                ->addLayout('Layer', 'layer', $this->layer_layout())
                ->confirmRemove('Sei sicuro di voler eliminare questo elemento?', 'Elimina', 'Annulla'),
        ];
    }

    protected function title_layout(): array
    {
        return [
            Text::make('Titolo', 'title')->rules('required'),
        ];
    }

    protected function layer_layout(): array
    {
        return [
            Select::make('Layer', 'layer')
                ->options(function () {
                    $layers = Layer::where('app_id', $this->model()->id)
                        ->get()
                        ->map(function ($layer) {
                            // Accesso al titolo translatable in modo più pulito
                            $title = $layer->properties['title'] ?? null;
                            if (is_array($title)) {
                                // Se è un array, prendi prima la versione italiana, poi quella inglese, altrimenti usa l'ID
                                $title = $title['it'] ?? $title['en'] ?? ('Layer #' . $layer->id);
                            } elseif (is_null($title)) {
                                $title = 'Layer #' . $layer->id;
                            }

                            return [
                                'id' => $layer->id,
                                'title' => $title
                            ];
                        });
                    $layers = $layers->sortBy('title');

                    return $layers->pluck('title', 'id')->all();
                })
                ->searchable()
                ->rules('required')
                ->help(__('Seleziona un layer esistente'))
                ->displayUsingLabels(),

            Image::make('Immagine Layer', 'image')
                ->resolveUsing(function ($value, $resource, $attribute) {
                    // Verifica se esiste un layer selezionato
                    $layerId = $resource['layer'];
                    if (empty($layerId)) {
                        return null;
                    }

                    // Recupera il layer selezionato
                    $layer = Layer::find($layerId);
                    if (!$layer) {
                        return null;
                    }

                    // Verifica se il layer ha un'immagine
                    $media = $layer->getFirstMedia('default');
                    if (!$media) {
                        return null;
                    }

                    // Restituisci l'URL dell'immagine del layer
                    $mediaService = MediaService::make();
                    return $mediaService->getThumbnailUrl($media);
                })
                ->help(__('Immagine del layer selezionato'))
                ->hideWhenCreating()
                ->hideFromIndex()
                ->readonly()
                ->disableDownload(),
        ];
    }
}
