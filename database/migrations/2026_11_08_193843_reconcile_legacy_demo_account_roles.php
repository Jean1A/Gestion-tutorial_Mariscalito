<?php

use App\Enums\RoleName;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        DB::transaction(function (): void {
            $roles = DB::table('roles')->pluck('id', 'name');
            $coordinator = $this->demoAccount('90000011', 'coordinator-preview@example.test');
            if ($coordinator && $roles->has('coordinator') && $roles->has('director')
                && DB::table('role_user')->where('user_id', $coordinator->id)->pluck('role_id')->all() === [$roles['director']]) {
                $coordinator->roles()->sync([$roles['coordinator']]);
                $coordinator->increment('session_version');
                app(AuditService::class)->record('demo.coordinator_role_reconciled', $coordinator, [
                    'before' => 'director',
                    'after' => RoleName::Coordinator->value,
                ]);
            }

            $guardian = $this->demoAccount('90000009', 'portal-preview@example.test');
            if ($guardian && $roles->has('guardian') && $roles->has('coordinator')
                && $guardian->roles()->whereKey($roles['guardian'])->exists()
                && $guardian->roles()->detach($roles['coordinator'])) {
                $guardian->increment('session_version');
                app(AuditService::class)->record('demo.unintended_role_removed', $guardian, ['role' => RoleName::Coordinator->value]);
            }

            $committee = $this->demoAccount('90000003', 'toe_committee@example.test');
            if ($committee?->is_active && ! DB::table('role_user')->where('user_id', $committee->id)->exists()) {
                $committee->update(['is_active' => false, 'session_version' => $committee->session_version + 1]);
                app(AuditService::class)->record('demo.retired_account_deactivated', $committee);
            }
        });
    }

    private function demoAccount(string $dni, string $email): ?User
    {
        return User::where('dni', $dni)->where('email', $email)->lockForUpdate()->first();
    }

    public function down(): void {}
};
