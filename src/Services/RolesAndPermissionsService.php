<?php

namespace Wm\WmPackage\Services;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsService
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public static function seedDatabase()
    {
        Role::firstOrCreate(['name' => 'Administrator']);
        Role::firstOrCreate(['name' => 'Validator']);
        Role::firstOrCreate(['name' => 'Guest']); //can login but no permissions

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
