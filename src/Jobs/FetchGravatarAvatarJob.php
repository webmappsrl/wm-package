<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\MimeTypes;
use Wm\WmPackage\Jobs\Abstract\BaseJob;
use Wm\WmPackage\Models\User;

/**
 * Fetches a user's Gravatar image asynchronously right after signup and, if a
 * real (non-default) avatar exists for their email, attaches it to the
 * `avatar` media collection.
 */
class FetchGravatarAvatarJob extends BaseJob
{
    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public ?int $appId = null) {}

    /**
     * Fetch the Gravatar image for the user's email and attach it as the
     * avatar, unless Gravatar has no real image (404) or the request failed
     * for another reason (e.g. rate-limited with 429, or a timeout/connection
     * error). Any such failure is logged distinctly and the job returns
     * cleanly — no retry.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $this->logInfo("User {$this->userId} not found, skipping Gravatar fetch");

            return;
        }

        $hash = md5(strtolower(trim($user->email)));

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get("https://www.gravatar.com/avatar/{$hash}", [
                    'd' => '404',
                    // 2x la dimensione della conversion avatar (User::AVATAR_CONVERSION_SIZE)
                    // per avere una fonte sufficientemente grande da croppare senza sfocatura.
                    's' => User::AVATAR_CONVERSION_SIZE * 2,
                ]);
        } catch (ConnectionException $e) {
            $this->logError("Gravatar fetch failed for user {$this->userId} due to a connection/timeout error: {$e->getMessage()} (not treated as 'no avatar')");

            return;
        }

        if ($response->status() === 404) {
            $this->logInfo("No real Gravatar for user {$this->userId} (404)");

            return;
        }

        if (! $response->successful()) {
            $this->logError("Gravatar fetch failed for user {$this->userId} with status {$response->status()} (not treated as 'no avatar')");

            return;
        }

        // A user-uploaded avatar always wins over Gravatar: this guards against the
        // job attaching (or re-attaching, on retry) a Gravatar image after the user
        // already uploaded their own avatar via AppAuthController::update().
        if ($user->getFirstMedia('avatar')) {
            $this->logInfo("User {$this->userId} already has an avatar, skipping Gravatar overwrite");

            return;
        }

        $contentType = strtok((string) $response->header('Content-Type'), ';');
        $extension = (new MimeTypes)->getExtensions($contentType)[0] ?? 'jpg';

        $tempPath = sys_get_temp_dir().'/gravatar_'.$this->userId.'_'.uniqid().'.'.$extension;
        file_put_contents($tempPath, $response->body());

        $mediaAdder = $user->addMedia($tempPath);
        if ($this->appId !== null) {
            // Pass app_id explicitly so MediaObserver doesn't fall back to the
            // hardcoded app_id = 1 default (FK violation / cross-tenant data).
            $mediaAdder = $mediaAdder->withAttributes(['app_id' => $this->appId]);
        }

        $mediaAdder->toMediaCollection('avatar');
    }

    /**
     * Get the Redis lock key for preventing job overlapping.
     */
    protected function getRedisLockKey(): string
    {
        return 'fetch_gravatar_avatar:'.$this->userId;
    }

    /**
     * Get the log channel for the job.
     */
    protected function getLogChannel(): string
    {
        return config('logging.default');
    }
}
