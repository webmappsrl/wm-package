<?php

namespace Wm\WmPackage\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Wm\WmPackage\Services\FeaturesService;

trait InteractsWithWmPackageMigrationStubs
{
    /**
     * Identificatori degli stub da considerare.
     *
     * Gli stub della root sono obbligatori per ogni consumer e vengono sempre
     * restituiti con il solo nome-base. Quelli di un dominio opzionale sono
     * inclusi solo se il dominio e' acceso in configurazione o passato in
     * $extraDomains, e sono qualificati come "<dominio>/<nome-base>".
     *
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubBaseNames(array $extraDomains = []): array
    {
        $paths = glob($this->stubsDirectory().'/*.stub') ?: [];

        $names = array_map(
            fn (string $path) => $this->baseNameFromStubPath($path),
            $paths,
        );

        foreach ($this->domainsInScope($extraDomains) as $domain) {
            // Una chiave vuota o con metacaratteri glob produrrebbe un pattern
            // arbitrario: con '' il pattern diventa ".../migrations//*.stub" e
            // ogni stub della root verrebbe riaggiunto duplicato.
            if (! preg_match('/^[a-z0-9_]+$/', $domain)) {
                continue;
            }

            $domainPaths = glob($this->stubsDirectory()."/{$domain}/*.stub") ?: [];

            foreach ($domainPaths as $path) {
                $names[] = $domain.'/'.$this->baseNameFromStubPath($path);
            }
        }

        $this->guardAgainstDuplicateBaseNames($names);

        return $names;
    }

    /**
     * Il lato pubblicato cerca per suffisso del nome file, che non contiene il
     * dominio: due stub con lo stesso nome-base — fra due domini o fra un
     * dominio e la root — sarebbero indistinguibili una volta pubblicati, e il
     * gate potrebbe risultare verde su uno stub mai pubblicato.
     *
     * Il vincolo non e' imponibile dal filesystem, quindi si fallisce presto e
     * con un messaggio esplicito invece di sbagliare in silenzio.
     *
     * @param  array<int, string>  $identifiers
     */
    protected function guardAgainstDuplicateBaseNames(array $identifiers): void
    {
        $baseNames = array_map(fn (string $i) => $this->baseNameFromIdentifier($i), $identifiers);
        $duplicates = array_unique(array_diff_assoc($baseNames, array_unique($baseNames)));

        if ($duplicates !== []) {
            throw new \RuntimeException(sprintf(
                'Stub con lo stesso nome-base in domini diversi: %s. '
                .'Una volta pubblicati sarebbero indistinguibili nel consumer, '
                .'dove le migration stanno in una cartella piatta.',
                implode(', ', $duplicates),
            ));
        }
    }

    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    protected function domainsInScope(array $extraDomains = []): array
    {
        return array_values(array_unique(array_merge(
            FeaturesService::enabledDomains(),
            $extraDomains,
        )));
    }

    /**
     * @param  string  $identifier  Nome-base per gli stub della root,
     *                              "<dominio>/<nome-base>" per quelli di un dominio.
     */
    public function findStubPath(string $identifier): ?string
    {
        $path = $this->stubsDirectory()."/{$identifier}.php.stub";

        return file_exists($path) ? $path : null;
    }

    /**
     * Nome-base di uno stub, senza il dominio: e' il nome con cui la migration
     * viene pubblicata nel consumer, dove tutte le migration stanno in una
     * cartella piatta.
     */
    public function baseNameFromIdentifier(string $identifier): string
    {
        return Str::afterLast($identifier, '/');
    }

    /**
     * Mirrors the suffix-matching logic used internally by
     * Spatie\LaravelPackageTools\Concerns\PackageServiceProvider\ProcessMigrations::generateMigrationName()
     * so a file already published by `vendor:publish` is always recognised here.
     */
    public function findPublishedFilenameForStub(string $identifier): ?string
    {
        $path = $this->findPublishedPathForStub($identifier);

        return $path !== null ? basename($path, '.php') : null;
    }

    public function findPublishedPathForStub(string $identifier): ?string
    {
        $needle = $this->baseNameFromIdentifier($identifier).'.php';
        $needleLength = strlen($needle);

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            if (substr($path, -$needleLength) === $needle) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function findPublishedPathsMatchingStubContent(string $identifier): array
    {
        $stubPath = $this->findStubPath($identifier);

        if ($stubPath === null) {
            return [];
        }

        $normalizedStub = $this->normalizeMigrationPhp(file_get_contents($stubPath));
        $matches = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            if ($this->normalizeMigrationPhp(file_get_contents($path)) === $normalizedStub) {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    public function publishedFileMatchesStubContent(string $identifier): bool
    {
        return $this->findPublishedPathsMatchingStubContent($identifier) !== [];
    }

    public function normalizeMigrationPhp(string $content): string
    {
        $content = preg_replace('/\r\n/', "\n", $content) ?? $content;
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return trim($content);
    }

    protected function stubsDirectory(): string
    {
        return dirname(__DIR__, 3).'/database/migrations';
    }

    protected function baseNameFromStubPath(string $path): string
    {
        return Str::of(basename($path))->replace(['.stub', '.php'], '')->toString();
    }

    /**
     * @return array<int, string>
     */
    public function schemaGapsForStub(string $identifier): array
    {
        $stubPath = $this->findStubPath($identifier);

        if ($stubPath === null) {
            return ["stub \"{$identifier}\" non trovato"];
        }

        $gaps = [];
        $content = file_get_contents($stubPath);

        foreach ($this->extractSchemaExpectations($content) as $table => $info) {
            if (! Schema::hasTable($table)) {
                $gaps[] = "manca tabella {$table}";

                continue;
            }

            foreach ($info['columns'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $gaps[] = "manca colonna {$table}.{$column}";
                }
            }
        }

        foreach ($this->extractRoleExpectations($content) as $roleName) {
            if (! DB::table('roles')->where('name', $roleName)->exists()) {
                $gaps[] = "manca ruolo {$roleName}";
            }
        }

        return $gaps;
    }

    public function isAppliedToDatabase(string $identifier): bool
    {
        if ($this->findStubPath($identifier) === null) {
            return false;
        }

        $gaps = $this->schemaGapsForStub($identifier);

        if ($gaps !== []) {
            return false;
        }

        if ($this->hasVerifiableSchemaExpectations($identifier)) {
            return true;
        }

        return $this->hasRunPublishedMigrationForStub($identifier);
    }

    public function needsPublishing(string $identifier): bool
    {
        if ($this->findStubPath($identifier) === null) {
            return false;
        }

        if ($this->isAppliedToDatabase($identifier)) {
            return false;
        }

        return ! $this->publishedFileMatchesStubContent($identifier);
    }

    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubsNeedingPublishing(array $extraDomains = []): array
    {
        return array_values(array_filter(
            $this->stubBaseNames($extraDomains),
            fn (string $identifier) => $this->needsPublishing($identifier),
        ));
    }

    /**
     * @param  array<int, string>  $extraDomains
     * @return array<int, string>
     */
    public function stubsPendingMigration(array $extraDomains = []): array
    {
        return array_values(array_filter(
            $this->stubBaseNames($extraDomains),
            fn (string $identifier) => $this->publishedFileMatchesStubContent($identifier)
                && ! $this->hasRunPublishedMigrationForStub($identifier),
        ));
    }

    public function publishStubToProject(string $identifier): string
    {
        $stubPath = $this->findStubPath($identifier);

        if ($stubPath === null) {
            throw new \InvalidArgumentException("Nessuno stub trovato per \"{$identifier}\".");
        }

        $timestamp = now()->format('Y_m_d_His');
        // Il dominio non entra nel nome del file: nel consumer le migration
        // stanno tutte in una cartella piatta, e una barra qui farebbe fallire
        // la copy() con un errore poco leggibile.
        $baseName = $this->baseNameFromIdentifier($identifier);
        $destination = database_path("migrations/{$timestamp}_{$baseName}.php");

        if (! copy($stubPath, $destination)) {
            throw new \RuntimeException("Impossibile pubblicare lo stub in {$destination}.");
        }

        return $destination;
    }

    /**
     * @return array<string, array{op: string, columns: array<int, string>}>
     */
    protected function extractSchemaExpectations(string $content): array
    {
        $expected = [];

        if (! preg_match_all(
            '/Schema::(create|table)\(\s*[\'"]([^\'"]+)[\'"]/s',
            $content,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return $expected;
        }

        foreach ($matches[0] as $index => $fullMatch) {
            $operation = $matches[1][$index][0];
            $table = $matches[2][$index][0];
            $start = $fullMatch[1];
            $slice = substr($content, $start, 4000);

            $columns = [];
            if (preg_match_all(
                '/\$table->(?!foreign|index|unique|primary|drop|timestamps|rememberToken|comment|softDeletes|morphs|uuid|constrained|nullable|default|after|change|renameColumn)\w*\(\s*[\'"]([^\'"]+)[\'"]/',
                $slice,
                $columnMatches,
            )) {
                $columns = array_values(array_unique($columnMatches[1]));
            }

            if (preg_match('/\$table->id\(/', $slice)) {
                $columns[] = 'id';
            }

            if (preg_match('/\$table->morphs\(\s*[\'"]([^\'"]+)[\'"]/', $slice, $morphMatch)) {
                $columns[] = $morphMatch[1].'_type';
                $columns[] = $morphMatch[1].'_id';
            }

            $expected[$table] = [
                'op' => $operation,
                'columns' => array_values(array_unique($columns)),
            ];
        }

        return $expected;
    }

    /**
     * @return array<int, string>
     */
    protected function extractRoleExpectations(string $content): array
    {
        if (! str_contains($content, 'insertOrIgnore') && ! str_contains($content, 'Role::')) {
            return [];
        }

        if (! preg_match_all("/['\"]name['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    protected function hasVerifiableSchemaExpectations(string $identifier): bool
    {
        $stubPath = $this->findStubPath($identifier);

        if ($stubPath === null) {
            return false;
        }

        $content = file_get_contents($stubPath);
        $schema = $this->extractSchemaExpectations($content);

        foreach ($schema as $info) {
            if ($info['columns'] !== []) {
                return true;
            }
        }

        return $this->extractRoleExpectations($content) !== [];
    }

    protected function hasRunPublishedMigrationMatchingStubContent(string $baseName): bool
    {
        foreach ($this->findPublishedPathsMatchingStubContent($baseName) as $path) {
            if (DB::table('migrations')->where('migration', basename($path, '.php'))->exists()) {
                return true;
            }
        }

        return false;
    }

    protected function hasRunPublishedMigrationForStub(string $identifier): bool
    {
        if ($this->hasRunPublishedMigrationMatchingStubContent($identifier)) {
            return true;
        }

        $path = $this->findPublishedPathForStub($identifier);

        if ($path === null) {
            return false;
        }

        return DB::table('migrations')->where('migration', basename($path, '.php'))->exists();
    }
}
