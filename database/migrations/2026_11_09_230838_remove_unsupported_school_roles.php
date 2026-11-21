<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $obsoleteRoles = DB::table('roles')->whereNotIn('name', [
                'administrator', 'coordinator', 'tutor', 'assistant', 'teacher', 'guardian', 'student',
            ])->select('id');
            $affectedUsers = DB::table('role_user')->whereIn('role_id', $obsoleteRoles)->select('user_id');
            $usersWithSupportedRole = DB::table('role_user')->whereNotIn('role_id', $obsoleteRoles)->select('user_id');

            DB::table('users')->where(fn ($users) => $users->whereIn('id', $affectedUsers)
                ->orWhere(fn ($unassigned) => $unassigned->where('is_active', true)->whereNotIn('id', $usersWithSupportedRole)))
                ->increment('session_version');
            DB::table('users')->where('is_active', true)->whereNotIn('id', $usersWithSupportedRole)
                ->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('permission_role')->whereIn('role_id', $obsoleteRoles)->delete();
            DB::table('role_user')->whereIn('role_id', $obsoleteRoles)->delete();
            $obsoleteRoles->delete();
        });
    }

    public function down(): void {}
};
