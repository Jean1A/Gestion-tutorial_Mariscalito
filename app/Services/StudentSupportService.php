<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Section;
use App\Models\Student;
use App\Models\SupportProfile;
use App\Models\SupportRecord;
use App\Models\User;
use App\Notifications\SchoolNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentSupportService
{
    public const KINDS = ['interview' => 'Entrevista', 'incident' => 'Incidencia', 'case' => 'Caso reservado de coordinación', 'recovery' => 'Plan de recuperación'];

    public const VISIBILITIES = ['reserved' => 'Reservado', 'internal' => 'Interno', 'shared' => 'Compartido con la familia'];

    public const STATUSES = ['open' => 'Abierto', 'following' => 'En seguimiento', 'closed' => 'Cerrado'];

    public const DIMENSIONS = ['personal' => 'Personal', 'social' => 'Social', 'learning' => 'De los aprendizajes'];

    public function coordinator(User $user): bool
    {
        return $user->can('coordinate-tutors');
    }

    public function teacherOfferings(User $user): Builder
    {
        return CourseOffering::where('teacher_id', $user->id)->where('is_active', true)
            ->whereHas('course', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('section', fn (Builder $query) => $query->where('is_active', true)->whereHas('schoolYear', fn (Builder $year) => $year->where('is_active', true)));
    }

    public function sections(User $user): Builder
    {
        if ($user->activeRole() === RoleName::Teacher && $user->permits('courses.record')) {
            return Section::whereIn('id', $this->teacherOfferings($user)->select('section_id'));
        }

        return app(AccessScope::class)->sections($user);
    }

    public function canReport(User $user): bool
    {
        return match ($user->activeRole()) {
            RoleName::Teacher => $user->permits('courses.record'),
            RoleName::Assistant => $user->permits('attendance.record') && $user->permits('students.view'),
            default => false,
        };
    }

    public function canCreate(User $user): bool
    {
        return $this->canReport($user) || $this->canWork($user);
    }

    public function students(User $user): Builder
    {
        if ($user->activeRole() === RoleName::Teacher) {
            return Student::whereHas('enrollments', fn (Builder $query) => $query
                ->whereIn('section_id', $this->sections($user)->select('sections.id')));
        }

        return app(AccessScope::class)->students($user);
    }

    public function canWork(User $user): bool
    {
        return ($user->activeRole() === RoleName::Administrator && $this->coordinator($user))
            || ($user->activeRole() === RoleName::Tutor && $user->permits('attendance.record') && $user->permits('students.view'));
    }

    public function records(User $user): Builder
    {
        $q = SupportRecord::query();
        if ($this->coordinator($user)) {
            return $q;
        }
        if ($user->activeRole() === RoleName::Guardian && $user->can('guardian-portal')) {
            return $q->where('visibility', 'shared')->whereNotNull('family_summary')->where('family_summary', '!=', '')->where('kind', '!=', 'case')->whereIn('student_id', app(AccessScope::class)->students($user)->select('students.id'));
        }
        if ($user->activeRole() === RoleName::Teacher && $this->canReport($user)) {
            return $q->where('kind', 'incident')->where('author_id', $user->id)
                ->whereIn('course_offering_id', $this->teacherOfferings($user)->select('id'));
        }
        if (in_array($user->activeRole(), [RoleName::Tutor, RoleName::Assistant], true) && $user->permits('students.view')) {
            $q->whereIn('section_id', app(AccessScope::class)->sections($user)->select('sections.id'))->where('kind', '!=', 'case');
            if ($user->activeRole() === RoleName::Assistant) {
                $q->where('kind', 'incident')->whereIn('visibility', ['internal', 'shared']);
            }

            return $q;
        }

        return $q->whereRaw('1=0');
    }

    public function canEdit(User $user, SupportRecord $record): bool
    {
        if (! $this->records($user)->whereKey($record->id)->exists()) {
            return false;
        }

        return ($this->canWork($user) || ($record->kind === 'case' && $this->coordinator($user)))
            && ! $record->section->schoolYear->closed_at;
    }

    public function canFollow(User $user, SupportRecord $record): bool
    {
        return $this->canEdit($user, $record) || ($this->canReport($user) && $record->kind === 'incident'
            && ! $record->section->schoolYear->closed_at && $this->records($user)->whereKey($record->id)->exists());
    }

    public function save(array $data, User $actor, ?SupportRecord $record = null): SupportRecord
    {
        return DB::transaction(function () use ($data, $actor, $record): SupportRecord {
            $section = Section::with('schoolYear')->whereKey($data['section_id'])->lockForUpdate()->firstOrFail();
            $student = Student::findOrFail($data['student_id']);
            if ($record) {
                $record = SupportRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->canEdit($actor, $record), 403);
            }
            if ($section->schoolYear->closed_at) {
                throw ValidationException::withMessages(['section_id' => 'El año de la sección está cerrado.']);
            }
            $allowed = $this->coordinator($actor) || $this->sections($actor)->whereKey($section->id)->exists();
            abort_unless($allowed, 403);
            abort_unless($record || $this->canWork($actor) || ($this->canReport($actor) && $data['kind'] === 'incident'), 403);
            if (! $student->enrollments()->where('section_id', $section->id)->whereDate('starts_on', '<=', $data['occurred_on'])->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['occurred_on']))->exists() || $data['occurred_on'] < $section->schoolYear->starts_on->toDateString() || $data['occurred_on'] > $section->schoolYear->ends_on->toDateString()) {
                throw ValidationException::withMessages(['student_id' => 'La fecha debe corresponder a una matrícula del estudiante en esta sección y año.']);
            }
            if ($record && ($record->student_id !== $student->id || $record->section_id !== $section->id || $record->kind !== $data['kind'])) {
                throw ValidationException::withMessages(['kind' => 'Un registro conserva estudiante, sección y tipo.']);
            }
            if ((int) $data['revision'] !== (int) ($record?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Otra persona modificó el registro. Recarga antes de guardar.']);
            }
            if ($data['kind'] === 'case') {
                $data['visibility'] = 'reserved';
            }
            if ($this->canReport($actor)) {
                $data['status'] = 'open';
                $data['severity'] = 'low';
                $data['visibility'] = 'internal';
                $data['family_summary'] = null;
                $data['closure_note'] = null;
                $data['dimension'] = null;
                $data['due_on'] = null;
            }
            if ($actor->activeRole() === RoleName::Teacher && ! $this->teacherOfferings($actor)->whereKey($data['course_offering_id'] ?? 0)->where('section_id', $section->id)->exists()) {
                throw ValidationException::withMessages(['course_offering_id' => 'Selecciona uno de tus cursos vigentes en la sección.']);
            }
            if ($data['visibility'] === 'shared' && empty($data['family_summary'])) {
                throw ValidationException::withMessages(['family_summary' => 'Escribe un resumen específico antes de publicar a la familia.']);
            }
            if ($data['visibility'] !== 'shared') {
                $data['family_summary'] = null;
            }
            if ($data['kind'] === 'recovery') {
                if (! CourseOffering::whereKey($data['course_offering_id'] ?? 0)->where('section_id', $section->id)->exists()) {
                    throw ValidationException::withMessages(['course_offering_id' => 'Selecciona un curso de la sección.']);
                }
            } elseif ($data['kind'] === 'incident') {
                $data['course_offering_id'] = $record?->course_offering_id ?? ($actor->activeRole() === RoleName::Teacher ? $data['course_offering_id'] : null);
            } else {
                $data['course_offering_id'] = null;
            }
            if ($data['status'] === 'closed' && empty($data['closure_note'])) {
                throw ValidationException::withMessages(['closure_note' => 'Explica el resultado y motivo del cierre.']);
            }
            if ($data['status'] === 'closed' && $data['kind'] === 'incident' && (! $record?->followUps()->exists() || empty($data['dimension']))) {
                throw ValidationException::withMessages(['closure_note' => 'Para cerrar una incidencia registra un seguimiento y selecciona la dimensión.']);
            }
            $data['closed_at'] = $data['status'] === 'closed' ? ($record?->closed_at ?? now()) : null;
            $data['revision'] = ($record?->revision ?? 0) + 1;
            $isNew = $record === null;
            $previousState = $record?->only(['status', 'visibility', 'severity', 'dimension', 'due_on']);
            $record ??= new SupportRecord(['author_id' => $actor->id]);
            $record->fill($data)->save();
            app(AuditService::class)->record('support.record_saved', $record, ['kind' => $record->kind, 'before' => $previousState, 'visibility' => $record->visibility, 'status' => $record->status, 'dimension' => $record->dimension], $actor->id);
            if ($isNew && $record->kind === 'incident' && $this->canReport($actor)) {
                $tutors = $section->tutors()->where('tutor_assignments.is_active', true)->where('users.is_active', true)->with('roles')->get();
                foreach ($tutors as $tutor) {
                    if ($tutor->hasRole(RoleName::Tutor)) {
                        $tutor->notify(new SchoolNotification('Incidencia por revisar', 'Hay un reporte nuevo en una de tus secciones.', route('support.show', $record), ['kind' => 'support_report', 'student_id' => $record->student_id, 'section_id' => $section->id]));
                    }
                }
            }

            return $record;
        });
    }

    public function profile(Student $student, int $yearId, User $actor): ?SupportProfile
    {
        abort_unless($this->canWork($actor) && $this->students($actor)->whereKey($student->id)->exists(), 403);

        return SupportProfile::where('student_id', $student->id)->where('school_year_id', $yearId)->first();
    }

    public function messageAllowed(User $actor, Student $student, User $tutor, User $guardian): bool
    {
        return $tutor->hasRole(RoleName::Tutor) && $guardian->hasRole(RoleName::Guardian) && $tutor->roles()->where('name', 'tutor')->whereHas('permissions', fn (Builder $q) => $q->where('name', 'communications.send'))->exists() && $tutor->roles()->where('name', 'tutor')->whereHas('permissions', fn (Builder $q) => $q->where('name', 'students.view'))->exists()
         && $guardian->roles()->where('name', 'guardian')->whereHas('permissions', fn (Builder $q) => $q->where('name', 'communications.respond'))->exists()
         && $guardian->students()->whereKey($student->id)->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->exists()
         && app(ConsentService::class)->hasValidConsent($guardian)
         && $tutor->tutoredSections()->where('tutor_assignments.is_active', true)->whereIn('sections.id', $student->enrollments()->where('status', 'active')->select('section_id'))->exists()
         && (($actor->activeRole() === RoleName::Guardian && $actor->id === $guardian->id) || ($actor->activeRole() === RoleName::Tutor && $actor->id === $tutor->id && $actor->permits('communications.send')));
    }

    /** @return array{level:string,attendance:bool,conduct:bool,achievement:bool} */
    public function risk(Student $student, int $yearId): array
    {
        $attendance = CourseAttendanceAlert::where('is_active', true)->whereHas('enrollment', fn (Builder $q) => $q->where('student_id', $student->id)->where('school_year_id', $yearId))->exists();
        $conduct = SupportRecord::where('student_id', $student->id)->whereHas('section', fn (Builder $q) => $q->where('school_year_id', $yearId))->where('kind', 'incident')->where('status', '!=', 'closed')->whereIn('severity', ['medium', 'high'])->exists();
        $grades = CourseResult::where('is_published', true)->whereHas('enrollment', fn (Builder $q) => $q->where('student_id', $student->id)->where('school_year_id', $yearId))->whereIn('achievement', ['B', 'C'])->exists();
        $high = SupportRecord::where('student_id', $student->id)->whereHas('section', fn (Builder $q) => $q->where('school_year_id', $yearId))->where('kind', 'incident')->where('severity', 'high')->where('status', '!=', 'closed')->exists();

        return ['level' => $high || ((int) $attendance + (int) $conduct + (int) $grades) >= 2 ? 'red' : ($attendance || $conduct || $grades ? 'yellow' : 'green'), 'attendance' => $attendance, 'conduct' => $conduct, 'achievement' => $grades];
    }
}
