<?php

namespace Wm\WmPackage\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsService
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

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public static function seedDatabase()
    {
        Role::firstOrCreate(['name' => 'Administrator']);
        Role::firstOrCreate(['name' => 'Validator']);
        Role::firstOrCreate(['name' => 'Guest']); // can login but no permissions

        Permission::firstOrCreate(['name' => 'validate source surveys']);
        Permission::firstOrCreate(['name' => 'validate pois']);
        Permission::firstOrCreate(['name' => 'validate tracks']);
        Permission::firstOrCreate(['name' => 'manage roles and permissions']);

        $adminRole = Role::where('name', 'Administrator')->first();
        $adminRole->givePermissionTo('validate source surveys');
        $adminRole->givePermissionTo('validate pois');
        $adminRole->givePermissionTo('validate tracks');
        $adminRole->givePermissionTo('manage roles and permissions');
    }
}
