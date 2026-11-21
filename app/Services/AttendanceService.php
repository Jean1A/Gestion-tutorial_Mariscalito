<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RoleName;
use App\Jobs\NotifyAttendance;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use App\Models\SchoolDay;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function roster(Section $section, string $date, ?AttendanceSession $session = null): Builder
    {
        return Enrollment::with('student')->where('section_id', $section->id)->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->where(function ($q) use ($session) {
            $q->whereHas('student', fn ($student) => $student->where('is_active', true));
            if ($session) {
                $q->orWhereIn('id', $session->records()->select('enrollment_id'));
            }
        });
    }

    public function record(Section $section, array $data, User $actor): AttendanceSession
    {
        return DB::transaction(function () use ($section, $data, $actor) {
            $section = Section::with('schoolYear', 'grade.educationLevel')->whereKey($section->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('recordAttendance', $section);
            $day = SchoolDay::where('school_year_id', $section->school_year_id)->whereDate('date', $data['date'])->lockForUpdate()->first();
            if (! $day?->is_instructional || ! $section->schoolYear->is_active || ! $section->grade->is_active || ! $section->grade->educationLevel->is_active || $data['date'] > now()->toDateString()) {
                throw ValidationException::withMessages(['date' => 'La fecha debe ser un día lectivo configurado de una sección y año activos, hasta hoy.']);
            }
            $session = AttendanceSession::where('section_id', $section->id)->where('school_day_id', $day->id)->lockForUpdate()->first();
            if ((int) $data['revision'] !== (int) ($session?->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Otra persona actualizó la asistencia. Recarga la pantalla antes de guardar.']);
            }
            if ($session && now()->greaterThan($session->created_at->addHours((int) app(SettingsService::class)->required('attendance.edit_hours')))) {
                throw ValidationException::withMessages(['date' => 'El plazo para editar esta asistencia venció.']);
            }
            $roster = $this->roster($section, $data['date'], $session)->lockForUpdate()->get()->keyBy('id');
            $submitted = array_column($data['records'], 'enrollment_id');
            $expected = $roster->keys()->map(fn ($v) => (int) $v)->all();
            sort($expected);
            $submitted = array_map('intval', $submitted);
            sort($submitted);
            if (! $expected || $submitted !== $expected) {
                throw ValidationException::withMessages(['records' => 'Registra toda la lista autorizada, sin estudiantes de otras secciones.']);
            }
            $session ??= AttendanceSession::create(['section_id' => $section->id, 'school_day_id' => $day->id, 'recorded_by' => $actor->id, 'revision' => 1]);
            $existing = $session->records()->lockForUpdate()->get()->keyBy('enrollment_id');
            foreach ($data['records'] as $input) {
                $status = AttendanceStatus::from($input['status']);
                $record = $existing->get($input['enrollment_id']) ?? new AttendanceRecord;
                $before = $record->exists ? ['status' => $record->status->value, 'arrival_time' => $record->arrival_time, 'justification' => $record->justification] : null;
                $present = in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late], true);
                if ($present && empty($input['arrival_time'])) {
                    throw ValidationException::withMessages(['records' => 'Presente y tardanza requieren hora de ingreso.']);
                }
                $after = ['status' => $status->value, 'arrival_time' => $present ? $input['arrival_time'].':00' : null, 'justification' => $status === AttendanceStatus::JustifiedAbsence ? ($input['justification'] ?? null) : null];
                if (($status === AttendanceStatus::JustifiedAbsence || $record->status === AttendanceStatus::JustifiedAbsence) && $before !== $after && $actor->activeRole() !== RoleName::Tutor) {
                    throw ValidationException::withMessages(['records' => 'Solo el tutor de la sección puede aprobar o modificar una justificación.']);
                }
                if ($status === AttendanceStatus::JustifiedAbsence && empty($after['justification'])) {
                    throw ValidationException::withMessages(['records' => 'Indica el sustento de la justificación aprobada por el tutor.']);
                }
                if ($before === $after) {
                    continue;
                }
                $record->fill(['attendance_session_id' => $session->id, 'enrollment_id' => $input['enrollment_id']] + $after)->save();
                app(AuditService::class)->record($before ? 'attendance.updated' : 'attendance.created', $record, ['before' => $before, 'after' => $after], $actor->id);
                if (in_array($status, [AttendanceStatus::Late, AttendanceStatus::UnjustifiedAbsence, AttendanceStatus::JustifiedAbsence], true) && (! $before || $before['status'] !== $status->value)) {
                    NotifyAttendance::dispatch($record->id, $status->value);
                }
            }
            if ((int) $data['revision'] !== 0) {
                $session->increment('revision');
            }

            return $session;
        });
    }
}
