<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\AcademicPeriod;
use App\Models\Communication;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\SupportFollowUp;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TutorCoordinationService
{
    public const ACTIVITY_LABELS = [
        'course.attendance_saved' => 'Registró o actualizó una asistencia',
        'justification.reviewed' => 'Revisó una justificación',
        'communication.sent' => 'Envió una comunicación a las familias',
        'support.record_saved' => 'Registró o actualizó una intervención',
        'support.follow_up_saved' => 'Registró un avance de seguimiento',
        'support.group_session_saved' => 'Actualizó una sesión de tutoría grupal',
    ];

    /** @return array{school_year_id: int, grade_id: ?int, period_id: ?int, section_id: ?int, tutor_id: ?int, dimension: ?string, from: string, to: string, q: string, account_status: string, status: string} */
    public function filters(array $input): array
    {
        $year = isset($input['school_year_id']) ? SchoolYear::available()->find($input['school_year_id']) : SchoolYear::available()->orderByDesc('is_active')->orderByDesc('year')->first();
        $end = $year ? max($year->starts_on->toDateString(), min(now()->toDateString(), $year->ends_on->toDateString())) : now()->toDateString();

        $period = ! empty($input['period_id']) ? AcademicPeriod::find($input['period_id']) : null;

        return [
            'school_year_id' => $year?->id ?? 0,
            'grade_id' => ! empty($input['grade_id']) ? (int) $input['grade_id'] : null,
            'period_id' => $period?->id,
            'section_id' => ! empty($input['section_id']) ? (int) $input['section_id'] : null,
            'tutor_id' => ! empty($input['tutor_id']) ? (int) $input['tutor_id'] : null,
            'from' => max($input['from'] ?? $year?->starts_on->toDateString() ?? $end, $period?->starts_on->toDateString() ?? ''),
            'to' => min($input['to'] ?? max($input['from'] ?? '', $end), $period?->ends_on->toDateString() ?? '9999-12-31'),
            'dimension' => $input['dimension'] ?? null,
            'q' => $input['q'] ?? '',
            'account_status' => $input['account_status'] ?? 'active',
            'status' => $input['status'] ?? 'pending',
        ];
    }

    public function options(array $filters): array
    {
        $allSections = array_replace($filters, ['section_id' => null, 'tutor_id' => null]);

        return [
            'filters' => $filters,
            'years' => SchoolYear::available()->orderByDesc('year')->get(),
            'filterSections' => $this->sections($allSections)->with('grade.educationLevel')->orderBy('grade_id')->orderBy('name')->get(),
            'filterTutors' => $this->tutors()->orderBy('name')->get(['users.id', 'name', 'is_active']),
            'filterGrades' => Grade::with('educationLevel')->get(),
            'filterPeriods' => AcademicPeriod::where('school_year_id', $filters['school_year_id'])->orderBy('starts_on')->get(),
        ];
    }

    public function tutors(): Builder
    {
        return User::whereHas('roles', fn (Builder $query) => $query->where('name', RoleName::Tutor->value));
    }

    public function sections(array $filters, bool $filterTutor = true): Builder
    {
        return Section::where('school_year_id', $filters['school_year_id'])
            ->when($filters['grade_id'] ?? null, fn (Builder $query, int $id) => $query->where('grade_id', $id))
            ->when($filters['section_id'], fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($filterTutor && $filters['tutor_id'], fn (Builder $query) => $query->whereHas('tutors', fn (Builder $tutors) => $tutors->where('users.id', $filters['tutor_id'])->where('tutor_assignments.is_active', true)->where('users.is_active', true)->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'tutor'))));
    }

    public function sessions(array $filters): Builder
    {
        return CourseAttendanceSession::whereHas('offering', fn (Builder $query) => $query->whereIn('section_id', $this->sections($filters, false)->select('sections.id')))
            ->whereHas('schoolDay', fn (Builder $query) => $query->where('is_instructional', true)->whereDate('date', '>=', $filters['from'])->whereDate('date', '<=', $filters['to'])->whereDate('date', '<=', now()->toDateString()))
            ->when($filters['tutor_id'], fn (Builder $query, int $id) => $query->where('recorded_by', $id));
    }

    public function justifications(array $filters): Builder
    {
        return AbsenceJustification::whereHas('record.session.offering', fn (Builder $query) => $query->whereIn('section_id', $this->sections($filters)->select('sections.id')));
    }

    public function communications(array $filters): Builder
    {
        return Communication::where('school_year_id', $filters['school_year_id'])->whereIn('user_id', $this->tutors()->select('users.id'))
            ->when($filters['tutor_id'], fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters['section_id'], fn (Builder $query) => $query->whereHas('recipients.student.enrollments', fn (Builder $enrollments) => $enrollments->where('section_id', $filters['section_id'])->where('school_year_id', $filters['school_year_id'])));
    }

    public function dashboard(array $filters): array
    {
        $sections = $this->sections($filters)->where('is_active', true);
        $hasTutor = fn (Builder $query) => $query->where('tutor_assignments.is_active', true)->where('users.is_active', true)->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'tutor'));
        $assigned = (clone $sections)->whereHas('tutors', $hasTutor)->count();
        $sectionCount = (clone $sections)->count();
        $counts = CourseAttendanceRecord::whereIn('course_attendance_session_id', $this->sessions($filters)->select('id'))->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $total = (int) $counts->sum();
        $communications = $this->during($this->communications($filters), 'created_at', $filters);
        $reviewCounts = $this->reviewCounts($filters);
        $sectionIds = (clone $sections)->select('sections.id');
        $interventions = SupportRecord::whereIn('section_id', $sectionIds)->where('status', '!=', 'closed')
            ->when($filters['dimension'] ?? null, fn (Builder $query, string $dimension) => $query->where('dimension', $dimension));
        $groupSessions = TutoringSession::whereIn('section_id', $sectionIds)
            ->whereBetween('scheduled_on', [$filters['from'], $filters['to']])
            ->where('status', '!=', 'cancelled')
            ->when($filters['dimension'] ?? null, fn (Builder $query, string $dimension) => $query->whereJsonContains('dimensions', $dimension));
        $stats = [
            'tutors' => $this->tutors()->where('is_active', true)->when($filters['tutor_id'], fn (Builder $query, int $id) => $query->whereKey($id))->when($filters['section_id'], fn (Builder $query, int $id) => $query->whereHas('tutoredSections', fn (Builder $sections) => $sections->where('sections.id', $id)->where('tutor_assignments.is_active', true)))->count(),
            'sections' => $sectionCount,
            'assigned_sections' => $assigned,
            'unassigned_sections' => $sectionCount - $assigned,
            'students' => Enrollment::whereIn('section_id', (clone $sections)->select('sections.id'))->where('status', 'active')->whereHas('student', fn (Builder $query) => $query->where('is_active', true))->distinct()->count('student_id'),
            'sessions' => $this->sessions($filters)->count(),
            'registered' => $total,
            'present' => (int) $counts->get('present', 0),
            'late' => (int) $counts->get('late', 0),
            'justified' => (int) $counts->get('justified_absence', 0),
            'unjustified' => (int) $counts->get('unjustified_absence', 0),
            'pending' => $this->justifications($filters)->where('status', 'pending')->count(),
            'reviewed' => (int) $reviewCounts->sum(),
            'communications' => (clone $communications)->count(),
            'appointments' => (clone $communications)->where('type', 'appointment')->count(),
            'risk_students' => $this->alerts($filters)->distinct()->count('enrollments.student_id'),
            'coverage' => $sectionCount ? round($assigned * 100 / $sectionCount) : 0,
            'open_interventions' => (clone $interventions)->count(),
            'open_cases' => (clone $interventions)->where('kind', 'case')->count(),
            'overdue_interventions' => (clone $interventions)->whereDate('due_on', '<', now()->toDateString())->count(),
            'scheduled_tutoring' => (clone $groupSessions)->count(),
            'completed_tutoring' => (clone $groupSessions)->where('status', 'completed')->count(),
            'absence_percentage' => $total ? round((int) $counts->get('unjustified_absence', 0) * 100 / $total, 1) : 0,
        ];
        $trend = $this->sessions($filters)->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->selectRaw('school_days.date, COUNT(*) AS total')->groupBy('school_days.date')->orderByDesc('school_days.date')->limit(10)->get()->reverse()->values();

        return compact('stats', 'trend') + [
            'tutorRows' => $this->tutorRows($filters, 5, $reviewCounts),
            'activities' => $this->activities($filters)->limit(5)->get(),
            'unassigned' => (clone $sections)->whereDoesntHave('tutors', $hasTutor)->with('grade.educationLevel')->limit(5)->get(),
        ];
    }

    public function tutorRows(array $filters, int $perPage = 12, ?Collection $reviewCounts = null): LengthAwarePaginator
    {
        $tutors = $this->tutors()->with(['tutoredSections' => fn ($query) => $query->where('school_year_id', $filters['school_year_id'])->where('tutor_assignments.is_active', true)->where('sections.is_active', true)->with('grade.educationLevel')])
            ->when($filters['account_status'] !== 'all', fn (Builder $query) => $query->where('is_active', $filters['account_status'] === 'active'))
            ->when($filters['tutor_id'], fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($filters['q'], fn (Builder $query, string $search) => $query->where(fn (Builder $names) => $names->where('name', 'like', '%'.$search.'%')->orWhere('dni', 'like', '%'.$search.'%')))
            ->when($filters['section_id'], fn (Builder $query) => $query->whereHas('tutoredSections', fn (Builder $sections) => $sections->where('sections.id', $filters['section_id'])->where('tutor_assignments.is_active', true)))
            ->orderBy('name')->paginate($perPage)->withQueryString();
        if ($tutors->isEmpty()) {
            return $tutors;
        }
        $tutorIds = $tutors->getCollection()->modelKeys();
        $sessionCounts = $this->sessions($filters)->whereIn('recorded_by', $tutorIds)->selectRaw('recorded_by, COUNT(*) AS aggregate')->groupBy('recorded_by')->pluck('aggregate', 'recorded_by');
        $communicationCounts = $this->during($this->communications($filters), 'created_at', $filters)->whereIn('user_id', $tutorIds)->selectRaw('user_id, COUNT(*) AS aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');
        $reviewCounts ??= $this->reviewCounts($filters, $tutorIds);
        $pendingCounts = DB::table('absence_justifications')->join('course_attendance_records', 'course_attendance_records.id', '=', 'absence_justifications.course_attendance_record_id')
            ->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')
            ->join('course_offerings', 'course_offerings.id', '=', 'course_attendance_sessions.course_offering_id')
            ->join('tutor_assignments', 'tutor_assignments.section_id', '=', 'course_offerings.section_id')
            ->whereIn('tutor_assignments.user_id', $tutorIds)->whereIn('course_offerings.section_id', $this->sections($filters, false)->select('sections.id'))->where('tutor_assignments.is_active', true)->where('absence_justifications.status', 'pending')
            ->selectRaw('tutor_assignments.user_id, COUNT(*) AS aggregate')->groupBy('tutor_assignments.user_id')->pluck('aggregate', 'user_id');
        foreach ($tutors as $tutor) {
            $tutor->setAttribute('coordination_metrics', ['sessions' => (int) $sessionCounts->get($tutor->id, 0), 'communications' => (int) $communicationCounts->get($tutor->id, 0), 'reviewed' => (int) $reviewCounts->get($tutor->id, 0), 'pending' => $tutor->is_active ? (int) $pendingCounts->get($tutor->id, 0) : 0]);
        }

        return $tutors;
    }

    public function alerts(array $filters): \Illuminate\Database\Query\Builder
    {
        return DB::table('course_attendance_alerts')->join('enrollments', 'enrollments.id', '=', 'course_attendance_alerts.enrollment_id')->where('course_attendance_alerts.is_active', true)->whereIn('enrollments.section_id', $this->sections($filters)->select('sections.id'));
    }

    public function alertRows(array $filters): LengthAwarePaginator
    {
        return CourseAttendanceAlert::with('enrollment.student', 'offering.course', 'offering.section.grade')
            ->whereIn('id', $this->alerts($filters)->selectRaw('MAX(course_attendance_alerts.id)')->groupBy('course_offering_id', 'enrollment_id', 'type'))
            ->orderByDesc('observed_value')->paginate(15, ['*'], 'alerts_page')->withQueryString();
    }

    public function activities(array $filters): \Illuminate\Database\Query\Builder
    {
        $recordIds = CourseAttendanceRecord::whereHas('session.offering', fn (Builder $query) => $query->whereIn('section_id', $this->sections($filters, false)->select('sections.id')))->select('id');
        $requests = $this->justifications(array_replace($filters, ['tutor_id' => null]))->select('id');

        return DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.user_id')->whereIn('audit_logs.user_id', $this->tutors()->select('users.id'))
            ->when($filters['tutor_id'], fn ($query, int $id) => $query->where('audit_logs.user_id', $id))
            ->whereBetween('audit_logs.created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])
            ->where(function ($query) use ($recordIds, $requests, $filters): void {
                $query->where(fn ($records) => $records->where('action', 'course.attendance_saved')->where('subject_type', (new CourseAttendanceRecord)->getMorphClass())->whereIn('subject_id', $recordIds))
                    ->orWhere(fn ($reviews) => $reviews->where('action', 'justification.reviewed')->where('subject_type', (new AbsenceJustification)->getMorphClass())->whereIn('subject_id', $requests))
                    ->orWhere(fn ($messages) => $messages->where('action', 'communication.sent')->where('subject_type', (new Communication)->getMorphClass())->whereIn('subject_id', $this->communications($filters)->select('id')));
                $sections = $this->sections($filters, false)->select('sections.id');
                $query->orWhere(fn ($records) => $records->where('action', 'support.record_saved')->where('subject_type', (new SupportRecord)->getMorphClass())->whereIn('subject_id', SupportRecord::whereIn('section_id', $sections)->select('id')))
                    ->orWhere(fn ($follow) => $follow->where('action', 'support.follow_up_saved')->where('subject_type', (new SupportFollowUp)->getMorphClass())->whereIn('subject_id', SupportFollowUp::whereHas('record', fn ($record) => $record->whereIn('section_id', $sections))->select('id')))
                    ->orWhere(fn ($sessions) => $sessions->where('action', 'support.group_session_saved')->where('subject_type', (new TutoringSession)->getMorphClass())->whereIn('subject_id', TutoringSession::whereIn('section_id', $sections)->select('id')));
            })->select('audit_logs.id', 'audit_logs.action', 'audit_logs.created_at', 'users.name', 'users.id as tutor_id')->orderByDesc('audit_logs.created_at')->orderByDesc('audit_logs.id');
    }

    public function assignmentSections(array $filters): LengthAwarePaginator
    {
        return $this->sections($filters)->with('grade.educationLevel', 'schoolYear', 'tutors.roles')->withCount(['enrollments' => fn (Builder $query) => $query->where('status', 'active')])->orderBy('grade_id')->orderBy('name')->paginate(12)->withQueryString();
    }

    public function saveAssignment(Section $section, array $data, User $actor): void
    {
        Gate::forUser($actor)->authorize('coordinate-tutors');
        DB::transaction(function () use ($section, $data, $actor): void {
            $section = Section::with('schoolYear', 'grade.educationLevel')->whereKey($section->id)->lockForUpdate()->firstOrFail();
            $tutor = User::whereKey($data['tutor_id'])->lockForUpdate()->first();
            $activate = (bool) $data['is_active'];
            if (! $tutor || ($activate && ! $tutor->hasRole(RoleName::Tutor))) {
                throw ValidationException::withMessages(['tutor_id' => 'Selecciona un tutor activo.']);
            }
            if ($section->schoolYear->closed_at || ($activate && (! $section->is_active || ! $section->grade->is_active || ! $section->grade->educationLevel->is_active))) {
                throw ValidationException::withMessages(['tutor_id' => 'Elige una sección habilitada en un año abierto o en preparación.']);
            }
            $before = DB::table('tutor_assignments')->where('section_id', $section->id)->get(['user_id', 'is_active'])->toArray();
            if ($activate) {
                DB::table('tutor_assignments')->where('section_id', $section->id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
                $section->tutors()->syncWithoutDetaching([$tutor->id => ['is_active' => true]]);
            } else {
                if (! DB::table('tutor_assignments')->where('section_id', $section->id)->where('user_id', $tutor->id)->exists()) {
                    throw ValidationException::withMessages(['tutor_id' => 'El tutor no tiene una asignación en esta sección.']);
                }
                $section->tutors()->updateExistingPivot($tutor->id, ['is_active' => false]);
            }
            app(AuditService::class)->record('coordination.tutor_assignment_saved', $section, ['before' => $before, 'tutor_id' => $tutor->id, 'is_active' => $activate], $actor->id);
        });
    }

    private function reviewCounts(array $filters, ?array $tutorIds = null): Collection
    {
        return $this->during($this->justifications(array_replace($filters, ['tutor_id' => null])), 'reviewed_at', $filters)
            ->whereIn('reviewed_by', $tutorIds ?? $this->tutors()->select('users.id'))->when($filters['tutor_id'], fn (Builder $query, int $id) => $query->where('reviewed_by', $id))
            ->selectRaw('reviewed_by, COUNT(*) AS aggregate')->groupBy('reviewed_by')->pluck('aggregate', 'reviewed_by');
    }

    public function during(Builder $query, string $column, array $filters): Builder
    {
        return $query->whereBetween($column, [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59']);
    }
}
