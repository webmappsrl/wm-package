<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

/**
 * Classe base per le azioni Nova relative alla gestione dei PBF
 * 
 * Fornisce la struttura comune e i trait necessari per le azioni PBF
 */
abstract class BasePbfAction extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * Esegue l'azione sui modelli forniti
     *
     * @param  ActionFields  $fields
     * @param  Collection  $models
     * @return mixed
     */
    abstract public function handle(ActionFields $fields, Collection $models);
}

