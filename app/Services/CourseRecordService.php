<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\AcademicPeriod;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\SchoolDay;
use App\Models\User;
use App\Notifications\SchoolNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CourseRecordService
{
    public function roster(CourseOffering $offering, string $date): Builder
    {
        return Enrollment::with('student')->where('section_id', $offering->section_id)->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date));
    }

    public function save(CourseOffering $offering, array $data, User $actor): CourseAttendanceSession
    {
        return DB::transaction(function () use ($offering, $data, $actor) {
            $offering = CourseOffering::with('course', 'section.schoolYear', 'section.grade.educationLevel')->whereKey($offering->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('record', $offering);
            $section = $offering->section;
            $day = SchoolDay::where('school_year_id', $section->school_year_id)->whereDate('date', $data['date'])->lockForUpdate()->first();
            if (! $day?->is_instructional || ! $section->is_active || ! $section->schoolYear->is_active || ! $section->grade->is_active || ! $section->grade->educationLevel->is_active || $data['date'] > now()->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Selecciona un día lectivo configurado de un año activo, hasta hoy.']);
            }
            $session = CourseAttendanceSession::where('course_offering_id', $offering->id)->where('school_day_id', $day->id)->lockForUpdate()->first();
            if ((int) $data['revision'] !== (int) ($session?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Otra persona guardó cambios. Recarga la asistencia.']);
            }
            if ($session && now()->greaterThan($session->created_at->copy()->addHours((int) app(SettingsService::class)->required('attendance.edit_hours')))) {
                throw ValidationException::withMessages(['date' => 'El plazo para editar la asistencia venció. Las justificaciones se tramitan por separado.']);
            }
            $ids = $this->roster($offering, $data['date'])->pluck('id')->map('intval')->sort()->values()->all();
            $submitted = collect($data['records'])->pluck('enrollment_id')->map('intval')->sort()->values()->all();
            if (! $ids || $ids !== $submitted) {
                throw ValidationException::withMessages(['records' => 'Registra la lista completa del curso, sin estudiantes ajenos.']);
            }
            $session ??= CourseAttendanceSession::create(['course_offering_id' => $offering->id, 'school_day_id' => $day->id, 'recorded_by' => $actor->id]);
            foreach ($data['records'] as $input) {
                $record = CourseAttendanceRecord::firstOrNew(['course_attendance_session_id' => $session->id, 'enrollment_id' => $input['enrollment_id']]);
                $before = $record->exists ? $record->only(['status', 'arrival_time']) : null;
                $status = AttendanceStatus::from($input['status']);
                if ($record->exists && $record->status !== $status && $record->justification()->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['records' => 'La falta tiene una solicitud pendiente. El tutor debe revisarla antes de cambiar el estado.']);
                }
                if (($status === AttendanceStatus::JustifiedAbsence || $record->status === AttendanceStatus::JustifiedAbsence) && $record->status !== $status) {
                    throw ValidationException::withMessages(['records' => 'Las faltas justificadas se aprueban por el tutor mediante su solicitud.']);
                }
                $present = in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true);
                if ($present && empty($input['arrival_time'])) {
                    throw ValidationException::withMessages(['records' => 'Presente y tardanza requieren hora de ingreso.']);
                }
                $record->fill(['status' => $status, 'arrival_time' => $present ? $input['arrival_time'].':00' : null]);
                if (! $record->exists || $record->isDirty()) {
                    $record->save();
                    app(AuditService::class)->record('course.attendance_saved', $record, ['before' => $before, 'after' => $record->only(['status', 'arrival_time'])], $actor->id);
                    if (in_array($status, [AttendanceStatus::Late, AttendanceStatus::UnjustifiedAbsence], true) && (! $before || $before['status'] !== $status)) {
                        $this->notifyGuardians($record);
                    }
                }
            }
            if ((int) $data['revision'] !== 0) {
                $session->increment('revision');
            }

            return $session;
        });
    }

    public function result(CourseOffering $offering, array $data, User $actor): void
    {
        DB::transaction(function () use ($offering, $data, $actor) {
            $offering = CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('grade', $offering);
            $enrollment = Enrollment::whereKey($data['enrollment_id'])->where('section_id', $offering->section_id)->first();
            $period = AcademicPeriod::whereKey($data['academic_period_id'])->where('school_year_id', $offering->section->school_year_id)->where('is_active', true)->first();
            if (! $enrollment || ! $period || $enrollment->starts_on->gt($period->ends_on) || $enrollment->ends_on?->lt($period->starts_on)) {
                throw ValidationException::withMessages(['enrollment_id' => 'La matrícula y el período deben pertenecer al curso y año.']);
            }
            $result = CourseResult::firstOrNew(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id]);
            $before = $result->exists ? $result->only(['achievement', 'is_published']) : null;
            if ($result->is_published && ($result->achievement !== $data['achievement'] || ! $data['is_published']) && empty($data['reason'])) {
                throw ValidationException::withMessages(['reason' => 'Registra el motivo para rectificar o retirar un logro publicado.']);
            }
            $result->fill(['achievement' => $data['achievement'], 'is_published' => $data['is_published'], 'recorded_by' => $actor->id])->save();
            app(AuditService::class)->record('course.result_saved', $result, ['before' => $before, 'after' => $result->only(['achievement', 'is_published']), 'reason' => $data['reason'] ?? null], $actor->id);
        });
    }

    public function submit(CourseAttendanceRecord $record, array $data, ?UploadedFile $document, User $actor): AbsenceJustification
    {
        abort_unless($actor->can('guardian-portal') && app(AccessScope::class)->students($actor)->whereKey($record->enrollment->student_id)->exists(), 403);

        return DB::transaction(function () use ($record, $data, $document, $actor) {
            $record = CourseAttendanceRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== AttendanceStatus::UnjustifiedAbsence) {
                throw ValidationException::withMessages(['reason' => 'Solo se puede solicitar justificación de una falta injustificada.']);
            }
            $request = AbsenceJustification::firstOrNew(['course_attendance_record_id' => $record->id]);
            if ($request->exists && ($request->status !== 'rejected' || $request->user_id !== $actor->id)) {
                throw ValidationException::withMessages(['reason' => 'Ya existe una solicitud para esta falta.']);
            }
            $before = $request->exists ? $request->only(['reason', 'description', 'status']) : null;
            $path = $document?->store('justifications', 'local');
            $request->fill(['user_id' => $actor->id, 'reason' => $data['reason'], 'description' => $data['description'], 'status' => 'pending', 'path' => $path, 'mime' => $document?->getMimeType(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null])->save();
            app(AuditService::class)->record('justification.submitted', $request, ['before' => $before, 'status' => 'pending'], $actor->id);

            return $request;
        });
    }

    public function review(AbsenceJustification $justification, array $data, User $actor): void
    {
        DB::transaction(function () use ($justification, $data, $actor) {
            $justification = AbsenceJustification::whereKey($justification->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('review', $justification);
            if ($justification->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'La solicitud ya fue revisada.']);
            }
            $record = CourseAttendanceRecord::whereKey($justification->course_attendance_record_id)->lockForUpdate()->firstOrFail();
            if ($record->status !== AttendanceStatus::UnjustifiedAbsence) {
                throw ValidationException::withMessages(['decision' => 'El registro de asistencia cambió; revisa la solicitud.']);
            }
            $before = $record->status->value;
            if ($data['decision'] === 'approved') {
                $record->update(['status' => AttendanceStatus::JustifiedAbsence]);
            }
            $justification->update(['status' => $data['decision'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['review_note']]);
            app(AuditService::class)->record('justification.reviewed', $justification, ['decision' => $data['decision'], 'review_note' => $data['review_note']], $actor->id);
            app(AuditService::class)->record('course.attendance_justification_reviewed', $record, ['before' => $before, 'after' => $record->status->value], $actor->id);
            $this->notifyGuardians($record);
        });
    }

    private function notifyGuardians(CourseAttendanceRecord $record): void
    {
        $record->load('enrollment.student.guardians', 'session.offering.course', 'session.schoolDay');
        foreach ($record->enrollment->student->guardians as $guardian) {
            if (! $guardian->hasRole(RoleName::Guardian) || ! $guardian->pivot->is_active || ! $guardian->pivot->verified_at || ! app(ConsentService::class)->hasValidConsent($guardian)) {
                continue;
            }
            $guardian->notify(new SchoolNotification('Asistencia · '.$record->session->offering->course->name, $record->enrollment->student->full_name.': '.$record->status->label().' · '.$record->session->schoolDay->date->format('d/m/Y'), route('portal.course', ['student' => $record->enrollment->student_id, 'offering' => $record->session->course_offering_id]), ['kind' => 'course_attendance', 'student_id' => $record->enrollment->student_id, 'course_record_id' => $record->id, 'status' => $record->status->value]));
        }
    }
}
