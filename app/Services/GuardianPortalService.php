<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\TutoringSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GuardianPortalService
{
    public function children(User $user): Collection
    {
        return app(AccessScope::class)->students($user)->with('enrollments.section.grade.educationLevel', 'enrollments.section.schoolYear')->orderBy('last_name')->get();
    }

    public function selected(User $user): ?Student
    {
        $id = request()->session()->get('guardian.student_id');

        return $id ? app(AccessScope::class)->students($user)->whereKey($id)->first() : null;
    }

    public function child(User $user, Student $student): Student
    {
        abort_unless(app(AccessScope::class)->students($user)->whereKey($student->id)->exists(), 403);

        return $student;
    }

    public function enrollment(Student $student): ?Enrollment
    {
        return $student->enrollments()->with('section.grade.educationLevel', 'section.schoolYear')->join('school_years', 'school_years.id', '=', 'enrollments.school_year_id')->select('enrollments.*')->whereDate('enrollments.starts_on', '<=', now()->toDateString())
            ->orderByDesc('school_years.is_active')->orderByDesc('school_years.year')->orderByDesc('enrollments.starts_on')->first();
    }

    public function offerings(Student $student): Builder
    {
        return CourseOffering::with('course', 'teacher', 'section.grade', 'section.schoolYear')->whereIn('section_id', $student->enrollments()->select('section_id'));
    }

    public function assertCourse(Student $student, CourseOffering $offering): void
    {
        abort_unless($student->enrollments()->where('section_id', $offering->section_id)->exists(), 403);
    }

    public function tutoringMaterials(Student $student): Builder
    {
        return TutoringSession::whereNotNull('materials')
            ->where('status', '!=', 'cancelled')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('enrollments')
                ->where('student_id', $student->id)
                ->whereColumn('enrollments.section_id', 'tutoring_sessions.section_id')
                ->whereColumn('enrollments.starts_on', '<=', 'tutoring_sessions.scheduled_on')
                ->where(fn ($dates) => $dates->whereNull('enrollments.ends_on')
                    ->orWhereColumn('enrollments.ends_on', '>=', 'tutoring_sessions.scheduled_on')));
    }

    public function records(Student $student, ?CourseOffering $offering = null): Builder
    {
        return CourseAttendanceRecord::with('session.schoolDay', 'session.offering.course', 'justification')->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))->whereHas('session.schoolDay', fn ($q) => $q->where('is_instructional', true)->whereDate('date', '<=', now()->toDateString()))->when($offering, fn ($q) => $q->whereHas('session', fn ($s) => $s->where('course_offering_id', $offering->id)));
    }

    public function summary(Builder $records): array
    {
        $counts = (clone $records)->selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $total = (int) $counts->sum();
        $unjustified = (int) $counts->get(AttendanceStatus::UnjustifiedAbsence->value, 0);

        return ['registered_sessions' => $total, 'attended' => (int) $counts->get('present', 0) + (int) $counts->get('late', 0), 'absent' => $unjustified + (int) $counts->get('justified_absence', 0), 'unjustified' => $unjustified, 'percentage' => $total ? round($unjustified * 100 / $total, 2) : 0.0];
    }
}
