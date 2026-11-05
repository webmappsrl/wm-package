<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Wm\WmPackage\Models\TaxonomyTerm;

/**
 * Genera la Resource Nova dedicata a una tassonomia di termini (oc:8910).
 *
 * La classe generata estende `AbstractTaxonomyTermsResource` e dichiara solo l'identifier della
 * tassonomia: elenco ristretto ai suoi termini, titolo ed etichette dal nome della tassonomia,
 * creazione già dentro. La voce di menu non va aggiunta a mano se la sezione delle tassonomie
 * usa `TaxonomyTermsMenu::items()`.
 */
class WmMakeTaxonomyResourceCommand extends Command
{
    protected $signature = 'wm:make-taxonomy-resource
        {identifier : Identifier della tassonomia in taxonomy_terms (es. warnings)}
        {--class= : Nome della classe da generare (default: identifier in StudlyCase)}
        {--uri-key= : uriKey da conservare, se la Resource sostituisce un indirizzo già in uso}
        {--force : Sovrascrive la classe se esiste già}';

    protected $description = 'Genera la Resource Nova di una tassonomia di termini';

    public function handle(): int
    {
        $identifier = (string) $this->argument('identifier');
        $class = (string) ($this->option('class') ?: Str::studly(str_replace([':', '.'], '-', $identifier)));
        $uriKey = $this->option('uri-key');

        $namespace = rtrim($this->laravel->getNamespace(), '\\').'\\Nova';
        $path = app_path('Nova/'.$class.'.php');

        if (file_exists($path) && ! $this->option('force')) {
            $this->error("Esiste già {$path}. Usa --force per sovrascriverla.");

            return self::FAILURE;
        }

        // La tassonomia assente non è un errore: la Resource può nascere prima della riga, e in
        // quel caso il suo elenco resta vuoto finché la tassonomia non c'è.
        $taxonomy = TaxonomyTerm::query()->taxonomies()->where('identifier', $identifier)->first();
        if ($taxonomy === null) {
            $this->warn("Nessuna tassonomia con identifier \"{$identifier}\": l'elenco resterà vuoto finché non la crei.");
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->render($namespace, $class, $identifier, is_string($uriKey) ? $uriKey : null));

        $this->info("Creata {$path}.");
        $this->line('Se la sezione delle tassonomie usa TaxonomyTermsMenu::items(), la voce di menu compare da sé.');

        return self::SUCCESS;
    }

    private function render(string $namespace, string $class, string $identifier, ?string $uriKey): string
    {
        $uriKeyMethod = $uriKey === null ? '' : <<<PHPCODE


                /** Conserva un indirizzo già in uso: i segnalibri continuano a funzionare. */
                public static function uriKey(): string
                {
                    return '{$uriKey}';
                }
            PHPCODE;

        return <<<PHPCODE
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Wm\WmPackage\Nova\AbstractTaxonomyTermsResource;

            /**
             * I termini della tassonomia «{$identifier}».
             */
            class {$class} extends AbstractTaxonomyTermsResource
            {
                public static function taxonomyIdentifier(): string
                {
                    return '{$identifier}';
                }{$uriKeyMethod}
            }

            PHPCODE;
    }
}
