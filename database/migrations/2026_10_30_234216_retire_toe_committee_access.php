<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'toe_committee')->value('id');
        if ($roleId) {
            $ids = DB::table('role_user')->where('role_id', $roleId)->pluck('user_id');
            DB::table('users')->whereIn('id', $ids)->increment('session_version');
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            DB::table('role_user')->where('role_id', $roleId)->delete();
        }
    }

    public function down(): void {}
};
