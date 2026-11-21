<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolDay;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AttendanceSummaryService
{
    public function records(Student $student, User $actor, ?int $yearId = null): Builder
    {
        $query = $this->base()->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id)->when($yearId, fn ($q) => $q->where('school_year_id', $yearId)));
        if ($actor->activeRole() !== RoleName::Guardian) {
            $query->whereHas('session', fn ($q) => $q->whereIn('section_id', app(AccessScope::class)->sections($actor)->select('sections.id')));
        }

        return $query;
    }

    public function base(): Builder
    {
        return AttendanceRecord::whereHas('session.schoolDay', fn ($q) => $q->where('is_instructional', true)->whereDate('date', '<=', now()->toDateString()));
    }

    public function percentage(Builder $query): array
    {
        $total = (clone $query)->count();
        $absences = (clone $query)->where('status', AttendanceStatus::UnjustifiedAbsence->value)->count();

        return ['registered_days' => $total, 'unjustified_absences' => $absences, 'percentage' => $total ? round($absences * 100 / $total, 2) : 0.0];
    }

    public function enrollment(Enrollment $enrollment): array
    {
        return $this->percentage($this->base()->where('enrollment_id', $enrollment->id));
    }

    public function consecutive(Enrollment $enrollment): int
    {
        $startsOn = Enrollment::where('student_id', $enrollment->student_id)->where('school_year_id', $enrollment->school_year_id)->min('starts_on');
        $days = SchoolDay::where('school_year_id', $enrollment->school_year_id)->where('is_instructional', true)->whereDate('date', '>=', $startsOn)->whereDate('date', '<=', now()->toDateString())->orderByDesc('date')->get();
        $count = 0;
        $records = AttendanceRecord::with('session')->whereHas('enrollment', fn ($q) => $q->where('student_id', $enrollment->student_id)->where('school_year_id', $enrollment->school_year_id))->get()->keyBy(fn ($record) => $record->session->school_day_id);
        foreach ($days as $day) {
            $record = $records->get($day->id);
            // Un día lectivo sin registro interrumpe la evidencia de ausencias consecutivas.
            if ($record?->status !== AttendanceStatus::UnjustifiedAbsence) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
