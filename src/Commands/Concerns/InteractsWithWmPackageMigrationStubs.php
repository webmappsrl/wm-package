<?php

namespace Wm\WmPackage\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait InteractsWithWmPackageMigrationStubs
{
    /**
     * @return array<int, string>
     */
    public function stubBaseNames(): array
    {
        $paths = glob($this->stubsDirectory().'/*.stub') ?: [];

        return array_map(
            fn (string $path) => $this->baseNameFromStubPath($path),
            $paths,
        );
    }

    public function findStubPath(string $baseName): ?string
    {
        $path = $this->stubsDirectory()."/{$baseName}.php.stub";

        return file_exists($path) ? $path : null;
    }

    /**
     * Mirrors the suffix-matching logic used internally by
     * Spatie\LaravelPackageTools\Concerns\PackageServiceProvider\ProcessMigrations::generateMigrationName()
     * so a file already published by `vendor:publish` is always recognised here.
     */
    public function findPublishedFilenameForStub(string $baseName): ?string
    {
        $path = $this->findPublishedPathForStub($baseName);

        return $path !== null ? basename($path, '.php') : null;
    }

    public function findPublishedPathForStub(string $baseName): ?string
    {
        $needle = "{$baseName}.php";
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
    public function findPublishedPathsMatchingStubContent(string $baseName): array
    {
        $stubPath = $this->findStubPath($baseName);

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

    public function publishedFileMatchesStubContent(string $baseName): bool
    {
        return $this->findPublishedPathsMatchingStubContent($baseName) !== [];
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
    public function schemaGapsForStub(string $baseName): array
    {
        $stubPath = $this->findStubPath($baseName);

        if ($stubPath === null) {
            return ["stub \"{$baseName}\" non trovato"];
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

    public function isAppliedToDatabase(string $baseName): bool
    {
        if ($this->findStubPath($baseName) === null) {
            return false;
        }

        $gaps = $this->schemaGapsForStub($baseName);

        if ($gaps !== []) {
            return false;
        }

        if ($this->hasVerifiableSchemaExpectations($baseName)) {
            return true;
        }

        return $this->hasRunPublishedMigrationForStub($baseName);
    }

    public function needsPublishing(string $baseName): bool
    {
        if ($this->findStubPath($baseName) === null) {
            return false;
        }

        if ($this->isAppliedToDatabase($baseName)) {
            return false;
        }

        return ! $this->publishedFileMatchesStubContent($baseName);
    }

    /**
     * @return array<int, string>
     */
    public function stubsNeedingPublishing(): array
    {
        return array_values(array_filter(
            $this->stubBaseNames(),
            fn (string $baseName) => $this->needsPublishing($baseName),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function stubsPendingMigration(): array
    {
        return array_values(array_filter(
            $this->stubBaseNames(),
            fn (string $baseName) => $this->publishedFileMatchesStubContent($baseName)
                && ! $this->hasRunPublishedMigrationForStub($baseName),
        ));
    }

    public function publishStubToProject(string $baseName): string
    {
        $stubPath = $this->findStubPath($baseName);

        if ($stubPath === null) {
            throw new \InvalidArgumentException("Nessuno stub trovato per \"{$baseName}\".");
        }

        $timestamp = now()->format('Y_m_d_His');
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

    protected function hasVerifiableSchemaExpectations(string $baseName): bool
    {
        $stubPath = $this->findStubPath($baseName);

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

    protected function hasRunPublishedMigrationForStub(string $baseName): bool
    {
        if ($this->hasRunPublishedMigrationMatchingStubContent($baseName)) {
            return true;
        }

        $path = $this->findPublishedPathForStub($baseName);

        if ($path === null) {
            return false;
        }

        return DB::table('migrations')->where('migration', basename($path, '.php'))->exists();
    }
}
