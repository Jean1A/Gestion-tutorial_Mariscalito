<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\User;
use App\Services\AccessScope;

class AbsenceJustificationPolicy
{
    public function view(User $user, AbsenceJustification $justification): bool
    {
        return $this->review($user, $justification) || ($user->activeRole() === RoleName::Guardian && $justification->user_id === $user->id && app(AccessScope::class)->students($user)->whereKey($justification->record->enrollment->student_id)->exists());
    }

    public function review(User $user, AbsenceJustification $justification): bool
    {
        return $user->activeRole() === RoleName::Tutor && $user->permits('attendance.record') && app(AccessScope::class)->sections($user)->whereKey($justification->record->session->offering->section_id)->exists();
    }
}
