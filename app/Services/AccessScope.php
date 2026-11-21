<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

class AccessScope
{
    public function notifications(User $user): MorphMany
    {
        $students = $this->students($user)->select('students.id');
        $notifications = $user->notifications()->where(fn ($query) => $query
            ->whereNull('data->student_id')->orWhereIn('data->student_id', $students));
        foreach (app(SchoolYearVisibility::class)->notificationSources() as $reference => $source) {
            $key = 'data->'.$reference;
            $notifications->where(fn ($query) => $query->whereNull($key)->orWhereIn($key, $source->select('id')));
        }

        return $notifications;
    }

    public function sections(User $user): Builder
    {
        $query = Section::query();
        if (! $user->permits('students.view')) {
            return $query->whereRaw('1 = 0');
        }

        return match ($user->activeRole()) {
            RoleName::Administrator, RoleName::Coordinator => $query,
            RoleName::Tutor => $query->whereIn('id', DB::table('tutor_assignments')->select('section_id')->where('user_id', $user->id)->where('is_active', true)),
            RoleName::Assistant => $query->whereExists(function ($assignment) use ($user) {
                $assignment->selectRaw('1')->from('assistant_assignments')
                    ->whereColumn('assistant_assignments.grade_id', 'sections.grade_id')
                    ->whereColumn('assistant_assignments.school_year_id', 'sections.school_year_id')
                    ->where('assistant_assignments.user_id', $user->id)->where('assistant_assignments.is_active', true);
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function students(User $user): Builder
    {
        $query = Student::visibleInSchoolYears();
        if (! $user->permits('students.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->activeRole() === RoleName::Guardian) {
            return $query->whereHas('guardians', fn ($guardian) => $guardian->where('users.id', $user->id)->where('guardian_student.is_active', true)->whereNotNull('guardian_student.verified_at'));
        }
        if (in_array($user->activeRole(), [RoleName::Administrator, RoleName::Coordinator], true)) {
            return $query;
        }

        return $query->whereHas('enrollments', fn ($enrollment) => $enrollment->whereIn('section_id', $this->sections($user)->select('sections.id')));
    }
}
