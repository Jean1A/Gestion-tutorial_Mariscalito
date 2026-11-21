<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore(['name' => 'tutors.coordinate', 'label' => 'Coordinar tutores y supervisar sus actividades']);
            $permissionId = DB::table('permissions')->where('name', 'tutors.coordinate')->value('id');
            foreach (DB::table('roles')->whereIn('name', ['administrator', 'director'])->pluck('id') as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'tutors.coordinate')->value('id');
        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
