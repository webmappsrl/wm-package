<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the shared well-known registry (apple-app-site-association / assetlinks.json)
 * hosted on the webapp server in sync with the `native_app_deep_link_enabled` toggle of
 * each App. The registry is shared across all Maphub instances, so every write only
 * touches the entry belonging to the app passed in, leaving all other entries intact.
 *
 * Takes primitives (sku, fingerprints) rather than an App model: removeAppEntry() is
 * called from a queued job that may run after the App row has already been deleted from
 * the database (see SyncWellKnownRegistryJob), so it cannot rely on re-fetching the model.
 *
 * v1 scope: no distributed locking (see docs/features/8251-toggle-qr-code-deep-link/overview.md,
 * section Rischi) — accepted for the low frequency of this admin action.
 */
class WellKnownRegistryService extends BaseService
{
    private const APPLE_FILE = 'apple-app-site-association';

    private const ANDROID_FILE = 'assetlinks.json';

    private const DISK = 'well_known_registry';

    public function addAppEntry(string $sku, ?string $androidCertSha256, ?string $appleTeamId = null): void
    {
        $this->syncAppleFile($sku, $appleTeamId, remove: false);
        $this->syncAndroidFile($sku, $androidCertSha256, remove: false);
    }

    public function removeAppEntry(string $sku, ?string $appleTeamId = null): void
    {
        $this->syncAppleFile($sku, $appleTeamId, remove: true);
        $this->syncAndroidFile($sku, null, remove: true);
    }

    private function syncAppleFile(string $sku, ?string $appleTeamId, bool $remove): void
    {
        $content = $this->downloadJson(self::APPLE_FILE, $this->defaultAppleStructure());

        if (! is_array($content) || ! isset($content['applinks']) || ! is_array($content['applinks'])) {
            Log::error('WellKnownRegistryService: '.self::APPLE_FILE.' has an unexpected structure, aborting sync.');

            return;
        }

        $details = $content['applinks']['details'] ?? [];
        $appId = $this->buildAppleAppId($sku, $appleTeamId);

        $details = array_values(array_filter(
            $details,
            fn ($detail) => ($detail['appID'] ?? null) !== $appId
        ));

        if (! $remove) {
            $details[] = [
                'appID' => $appId,
                'paths' => ['*'],
            ];
        }

        $content['applinks']['details'] = $details;

        $this->uploadJson(self::APPLE_FILE, $content, fn ($decoded) => is_array($decoded) && isset($decoded['applinks']));
    }

    private function syncAndroidFile(string $sku, ?string $androidCertSha256, bool $remove): void
    {
        $entries = $this->downloadJson(self::ANDROID_FILE, []);

        if (! is_array($entries)) {
            Log::error('WellKnownRegistryService: '.self::ANDROID_FILE.' has an unexpected structure, aborting sync.');

            return;
        }

        $entries = array_values(array_filter(
            $entries,
            fn ($entry) => ($entry['target']['package_name'] ?? null) !== $sku
        ));

        if (! $remove) {
            $fingerprints = $this->parseFingerprints($androidCertSha256);

            if (empty($fingerprints)) {
                Log::warning("WellKnownRegistryService: cannot add Android entry for app {$sku}, android_cert_sha256 is empty.");
            } else {
                $entries[] = [
                    'relation' => ['delegate_permission/common.handle_all_urls'],
                    'target' => [
                        'namespace' => 'android_app',
                        'package_name' => $sku,
                        'sha256_cert_fingerprints' => $fingerprints,
                    ],
                ];
            }
        }

        $this->uploadJson(self::ANDROID_FILE, $entries, function ($decoded) {
            if (! is_array($decoded)) {
                return false;
            }

            foreach ($decoded as $entry) {
                if (! isset($entry['target']['package_name'], $entry['target']['sha256_cert_fingerprints'])) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Splits the comma-separated `android_cert_sha256` field into a list of fingerprints
     * (e.g. upload key + Play Store signing key), trimming whitespace around each one.
     */
    private function parseFingerprints(?string $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Uses the App's own `apple_team_id` if set in Nova, otherwise falls back to the
     * package-wide default (single Webmapp Developer account) — see config/wm-package.php.
     */
    private function buildAppleAppId(string $sku, ?string $appleTeamId): string
    {
        $teamId = $appleTeamId ?: config('wm-package.deep_link.apple_team_id');

        return "{$teamId}.{$sku}";
    }

    private function defaultAppleStructure(): array
    {
        return ['applinks' => ['apps' => [], 'details' => []]];
    }

    /**
     * Downloads and decodes an existing well-known file, returning $default if it
     * doesn't exist yet. Throws if the existing remote file is not valid JSON, to
     * avoid overwriting a file we can't safely merge into.
     */
    private function downloadJson(string $filename, $default)
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($filename)) {
            return $default;
        }

        $decoded = json_decode($disk->get($filename), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("WellKnownRegistryService: existing {$filename} on the remote registry is not valid JSON, aborting sync to avoid data loss.");

            throw new \RuntimeException("Well-known file {$filename} is malformed on the remote registry, aborting.");
        }

        return $decoded;
    }

    /**
     * Backs up the existing remote file (if any) with a timestamp suffix, validates
     * the new content is well-formed JSON matching the expected shape, then uploads it.
     */
    private function uploadJson(string $filename, $content, callable $isValidStructure): void
    {
        $encoded = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || ! $isValidStructure(json_decode($encoded, true))) {
            Log::error("WellKnownRegistryService: refusing to upload malformed {$filename}.");

            throw new \RuntimeException("Refusing to upload malformed well-known file {$filename}.");
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($filename)) {
            $disk->copy($filename, $filename.'.backup-'.now()->format('Ymd-His'));
        }

        $disk->put($filename, $encoded);
    }
}
