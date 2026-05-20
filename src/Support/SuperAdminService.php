<?php

namespace Wm\WmPackage\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Verifica accesso basata su allowlist email per utenti super-admin (staff interno).
 *
 * Riutilizzabile da Nova ({@see \Laravel\Nova\Actions\Action}, risorse),
 * middleware, comandi HTTP o CLI (tramite {@see self::allowsUser} / {@see self::allowsEmail}).
 */
final class SuperAdminService
{
    public static function allows(?Request $request): bool
    {
        $user = $request !== null ? $request->user() : null;

        return self::allowsUser($user);
    }

    public static function allowsUser(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $email = $user->email ?? null;

        return self::allowsEmail(is_string($email) ? $email : null);
    }

    public static function allowsEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        /** @var array<int, string> $allowed */
        $allowed = config('wm-package.super_admin_emails', ['team@webmapp.it']);

        return in_array($email, $allowed, true);
    }
}
