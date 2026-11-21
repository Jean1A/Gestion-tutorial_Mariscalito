<?php

namespace App\Services;

use App\Models\GuardianConsent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsentService
{
    public function students(User $user): array
    {
        return $user->students()->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->pluck('students.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function policyHash(): string
    {
        return hash('sha256', view('privacy.policy-content')->render());
    }

    public function current(User $user): ?GuardianConsent
    {
        return GuardianConsent::where('user_id', $user->id)->where('policy_version', config('school.policy_version'))->where('policy_hash', $this->policyHash())->whereNull('withdrawn_at')->latest('id')->first();
    }

    public function hasValidConsent(User $user): bool
    {
        $consent = $this->current($user);

        return $consent && array_diff($this->students($user), $consent->student_ids ?? []) === [];
    }

    public function accept(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $consent = GuardianConsent::create(['user_id' => $user->id, 'policy_version' => config('school.policy_version'), 'policy_hash' => $this->policyHash(), 'student_ids' => $this->students($user), 'accepted_at' => now(), 'ip_address' => request()->ip()]);
            app(AuditService::class)->record('privacy.consent_accepted', $consent);
        });
    }

    public function withdraw(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach (GuardianConsent::where('user_id', $user->id)->whereNull('withdrawn_at')->lockForUpdate()->get() as $consent) {
                $consent->update(['withdrawn_at' => now()]);
                app(AuditService::class)->record('privacy.consent_withdrawn', $consent);
            }
        });
    }
}
