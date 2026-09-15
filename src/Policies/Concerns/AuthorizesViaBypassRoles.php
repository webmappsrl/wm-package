<?php

namespace Wm\WmPackage\Policies\Concerns;

use App\Models\User;

/**
 * Shared `before()` hook: any role listed in `bypassRoles()` is granted every
 * ability on the policy, bypassing all other methods (Laravel's standard
 * `before()` semantics — returning nothing/null falls through to the policy's
 * own methods).
 */
trait AuthorizesViaBypassRoles
{
    /**
     * Roles that bypass every ability of this policy. Override in the policy
     * class to change which roles get full access.
     *
     * @return array<int, string>
     */
    protected function bypassRoles(): array
    {
        return ['Administrator'];
    }

    /**
     * Perform pre-authorization checks.
     *
     * @return void|bool
     */
    public function before(User $user, string $ability)
    {
        foreach ($this->bypassRoles() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }
    }
}
