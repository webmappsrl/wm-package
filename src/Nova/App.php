<?php

namespace Wm\WmPackage\Nova;

use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Laravel\Nova\Tabs\Tab;
use Marshmallow\Tiptap\Tiptap;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Actions\UpdateTracksOnAws;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;

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
                Tab::make('home', $this->home_tab()),
                Tab::make('webapp', $this->webapp_tab()),
                Tab::make('app', $this->app_tab()),
                Tab::make('release_data', $this->app_release_data_tab()),
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

    protected function webapp_tab(): array
    {

        return [
            Boolean::make(__('Show Auth at startup'), 'webapp_auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),

        ];

    }

    protected function app_tab(): array
    {
        return [
            Boolean::make(__('Show Auth at startup'), 'auth_show_at_startup')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Shows the authentication and registration page for users')),
            Boolean::make(__('Geolocation Record Enable'), 'geolocation_record_enable')
                ->default(false)
                ->hideFromIndex()
                ->help(__('Enables user geolocation recording on tracks')),

        ];
    }

    protected function home_tab(): array
    {
        return [
            NovaTabTranslatable::make([
                Code::make('Welcome', 'welcome'),
                /*   Tiptap::make('Welcome', 'welcome')
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
                        '|',
                        'editHtml',
                    ])
                    ->headingLevels([2, 3, 4]),*/
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
    protected function app_release_data_tab(): array
    {
        return [
            Text::make(__('Name'), 'name')
                ->sortable()
                ->required()
                ->help(__('App name on the stores (App Store and Playstore).')),
            Textarea::make(__('Short Description'), 'short_description')
                ->hideFromIndex()
                ->rules('max:80')
                ->help(__('Max 80 characters. To be used as a promotional message also.')),
            Tiptap::make(__('Long Description'), 'long_description')
                ->hideFromIndex()
                ->rules('max:4000')
                ->help(__('Max 4000 characters.'))
                ->help(__('App description on the stores.')),
            Text::make(__('Keywords'), 'keywords')
                ->hideFromIndex()
                ->help(__('Comma separated Keywords e.g. "hiking,trekking,map"')),
            Text::make(__('Privacy Url'), 'privacy_url')
                ->hideFromIndex()
                ->help(__('Url to the privacy policy')),
            Text::make(__('Website Url'), 'website_url')
                ->hideFromIndex()
                ->help(__('Url to the website')),
            Images::make(__('Icon'), 'icon')
                ->rules('image', 'mimes:png', 'dimensions:width=1024,height=1024')
                ->help(__('Required size is :widthx:heightpx', ['width' => 1024, 'height' => 1024]))
                ->hideFromIndex(),
            Images::make(__('Splash image'), 'splash')
                ->rules('image', 'mimes:png', 'dimensions:width=2732,height=2732')
                ->help(__('Required size is :widthx:heightpx', ['width' => 2732, 'height' => 2732]))
                ->hideFromIndex(),
            Images::make(__('Icon small'), 'icon_small')
                ->rules('image', 'mimes:png', 'dimensions:width=512,height=512')
                ->disk('public')
                ->path('api/app/'.$this->model()->id.'/resources')
                ->storeAs(function () {
                    return 'icon_small.png';
                })
                ->help(__('Required size is :widthx:heightpx', ['width' => 512, 'height' => 512]))
                ->hideFromIndex(),
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
                                $title = $title['it'] ?? $title['en'] ?? ('Layer #'.$layer->id);
                            } elseif (is_null($title)) {
                                $title = 'Layer #'.$layer->id;
                            }

                            return [
                                'id' => $layer->id,
                                'title' => $title,
                            ];
                        });
                    $layers = $layers->sortBy('title');

                    return $layers->pluck('title', 'id')->all();
                })
                ->searchable()
                ->rules('required')
                ->help(__('Seleziona un layer esistente'))
                ->displayUsingLabels(),
        ];
    }
}
