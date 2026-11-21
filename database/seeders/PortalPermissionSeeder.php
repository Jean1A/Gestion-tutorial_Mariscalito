<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PortalPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        foreach (['administrator' => ['courses.manage', 'family.manage'], 'tutor' => ['courses.record'], 'teacher' => ['courses.record'], 'assistant' => ['courses.record']] as $name => $permissions) {
            $role = Role::where('name', $name)->firstOrFail();
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $permissions)->pluck('id'));
        }
    }
}
