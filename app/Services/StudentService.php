<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\CourseAttendanceRecord;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentService
{
    public function save(array $data, ?Student $subject = null, ?int $actorId = null, bool $allowPreparation = false): Student
    {
        return DB::transaction(function () use ($data, $subject, $actorId, $allowPreparation) {
            $section = Section::with('schoolYear', 'grade.educationLevel')->whereKey($data['section_id'])->lockForUpdate()->firstOrFail();
            $year = $section->schoolYear;
            if (! $section->is_active || $year->closed_at || (! $year->is_active && ! $allowPreparation) || ! $section->grade->is_active || ! $section->grade->educationLevel->is_active) {
                throw ValidationException::withMessages(['section_id' => 'Selecciona una sección habilitada en un año que permita matrículas.']);
            }
            if ($data['starts_on'] < $year->starts_on->toDateString() || $data['starts_on'] > $year->ends_on->toDateString() || (! empty($data['ends_on']) && $data['ends_on'] > $year->ends_on->toDateString())) {
                throw ValidationException::withMessages(['starts_on' => 'La matrícula debe estar dentro del año escolar.']);
            }
            $student = $subject ? Student::whereKey($subject->id)->lockForUpdate()->firstOrFail() : new Student;
            $before = $student->exists ? $student->only(['dni', 'first_name', 'last_name', 'birth_date', 'is_active']) : null;
            $student->fill(array_intersect_key($data, array_flip(['dni', 'first_name', 'last_name', 'birth_date', 'is_active'])))->save();
            $current = $student->enrollments()->where('school_year_id', $year->id)->whereNull('ends_on')->lockForUpdate()->first();
            if ($current && $current->section_id != $section->id) {
                if ($data['starts_on'] <= $current->starts_on->toDateString()) {
                    throw ValidationException::withMessages(['starts_on' => 'El traslado debe empezar después de la matrícula anterior.']);
                }
                $previous = $current->only(['status', 'ends_on']);
                $this->ensureHistoryFits($current, Carbon::parse($data['starts_on'])->subDay()->toDateString());
                $current->update(['status' => 'transferred', 'ends_on' => Carbon::parse($data['starts_on'])->subDay()->toDateString()]);
                app(AuditService::class)->record('enrollment.transferred', $current, ['before' => $previous, 'after' => $current->only(['status', 'ends_on'])], $actorId);
                $current = null;
            }
            $enrollment = $current ?? $student->enrollments()->where('school_year_id', $year->id)->where('section_id', $section->id)->whereDate('starts_on', $data['starts_on'])->first() ?? new Enrollment;
            if ($enrollment->exists && $enrollment->starts_on->toDateString() !== $data['starts_on']) {
                throw ValidationException::withMessages(['starts_on' => 'La fecha inicial de una matrícula existente se conserva.']);
            }
            if ($student->enrollments()->where('school_year_id', $year->id)->when($enrollment->exists, fn ($q) => $q->whereKeyNot($enrollment->id))->where('starts_on', '<=', $data['ends_on'] ?? $year->ends_on->toDateString())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Las matrículas del estudiante no pueden superponerse.']);
            }
            if ($enrollment->exists) {
                $this->ensureHistoryFits($enrollment, $data['ends_on'] ?? null);
            }
            $enrollment->fill(['student_id' => $student->id, 'school_year_id' => $year->id, 'section_id' => $section->id, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'status' => $data['status']])->save();
            app(AuditService::class)->record('student.saved', $student, ['before' => $before, 'after' => $student->only(['dni', 'first_name', 'last_name', 'birth_date', 'is_active'])], $actorId);
            app(AuditService::class)->record('enrollment.saved', $enrollment, [], $actorId);

            return $student;
        });
    }

    private function ensureHistoryFits(Enrollment $enrollment, ?string $end): void
    {
        if ($end && (AttendanceRecord::where('enrollment_id', $enrollment->id)->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', '>', $end))->exists() || CourseAttendanceRecord::where('enrollment_id', $enrollment->id)->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', '>', $end))->exists())) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha final no puede excluir asistencia ya registrada.']);
        }
        if ($end && DB::table('student_tutoring_accesses')->where('enrollment_id', $enrollment->id)->whereDate('accessed_at', '>', $end)->exists()) {
            throw ValidationException::withMessages(['ends_on' => 'La fecha final no puede excluir participación ya registrada en la sala de cómputo.']);
        }
    }
}
