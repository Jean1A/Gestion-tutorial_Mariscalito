<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AccessScope;

class CourseOfferingPolicy
{
    public function view(User $user, CourseOffering $offering): bool
    {
        if ($user->can('manage-courses')) {
            return true;
        }
        if ($user->activeRole() === RoleName::Guardian) {
            return app(AccessScope::class)->students($user)->whereHas('enrollments', fn ($q) => $q->where('section_id', $offering->section_id))->exists();
        }
        if ($user->activeRole() === RoleName::Teacher) {
            return $user->permits('courses.record') && $offering->teacher_id === $user->id;
        }

        return in_array($user->activeRole(), [RoleName::Tutor, RoleName::Assistant], true)
            && $user->permits('attendance.view') && app(AccessScope::class)->sections($user)->whereKey($offering->section_id)->exists();
    }

    public function record(User $user, CourseOffering $offering): bool
    {
        if (! $user->permits('courses.record') || ! $offering->is_active || ! $offering->course->is_active) {
            return false;
        }
        if ($offering->section->schoolYear->closed_at) {
            return false;
        }

        if ($user->activeRole() === RoleName::Tutor) {
            return $offering->course->is_tutoring && $user->permits('attendance.record')
                && app(AccessScope::class)->sections($user)->whereKey($offering->section_id)->exists();
        }

        return $this->grade($user, $offering);
    }

    public function exportResults(User $user, CourseOffering $offering): bool
    {
        return $user->activeRole() === RoleName::Teacher && $user->permits('courses.record') && $offering->teacher_id === $user->id;
    }

    public function grade(User $user, CourseOffering $offering): bool
    {
        return $user->permits('courses.record') && $user->activeRole() === RoleName::Teacher && ! $offering->section->schoolYear->closed_at && $offering->teacher_id === $user->id && $offering->is_active && $offering->course->is_active;
    }
}
