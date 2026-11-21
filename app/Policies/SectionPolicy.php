<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Section;
use App\Models\User;
use App\Services\AccessScope;

class SectionPolicy
{
    public function view(User $user, Section $section): bool
    {
        return app(AccessScope::class)->sections($user)->whereKey($section->id)->exists();
    }

    public function recordAttendance(User $user, Section $section): bool
    {
        return $user->permits('attendance.record') && $section->is_active && $section->schoolYear->is_active && $this->view($user, $section)
            && ($user->activeRole() === RoleName::Assistant || (config('school.legacy_attendance_enabled') && $user->activeRole() === RoleName::Tutor));
    }
}
