<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordRecoveryService
{
    public function requestLink(string $email): void
    {
        $user = User::where('email', $email)->where('is_active', true)->whereNotNull('email_verified_at')->first();
        if ($user) {
            Password::sendResetLink(['email' => $email, 'is_active' => true]);
        }
    }

    public function reset(array $data): bool
    {
        if (! User::where('email', $data['email'])->where('is_active', true)->whereNotNull('email_verified_at')->exists()) {
            return false;
        }
        $result = Password::reset($data + ['is_active' => true], function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $user->forceFill(['password' => $password, 'must_change_password' => false, 'session_version' => $user->session_version + 1, 'remember_token' => Str::random(60)])->save();
                app(AuditService::class)->record('auth.password_recovered', $user, [], $user->id);
            });
        });

        return $result === Password::PasswordReset;
    }
}
