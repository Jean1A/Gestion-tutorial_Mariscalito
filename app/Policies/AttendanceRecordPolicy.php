<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AccessScope;

class AttendanceRecordPolicy
{
    public function view(User $user, AttendanceRecord $record): bool
    {
        if (! $user->permits('attendance.view')) {
            return false;
        }

        return $user->activeRole() === RoleName::Guardian
            ? app(AccessScope::class)->students($user)->whereKey($record->enrollment->student_id)->exists()
            : app(AccessScope::class)->sections($user)->whereKey($record->session->section_id)->exists();
    }

    public function update(User $user, AttendanceRecord $record): bool
    {
        return $user->can('recordAttendance', $record->session->section);
    }
}
