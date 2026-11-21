<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function save(array $data, User $actor): void
    {
        if ($data['type'] === 'tutor') {
            $tutor = User::where('dni', $data['user_dni'])->firstOrFail();
            app(TutorCoordinationService::class)->saveAssignment(Section::findOrFail($data['section_id']), ['tutor_id' => $tutor->id, 'is_active' => $data['is_active']], $actor);

            return;
        }
        DB::transaction(function () use ($data, $actor) {
            $user = User::where('dni', $data['user_dni'])->lockForUpdate()->firstOrFail();
            if (! $user->hasRole(RoleName::from($data['type']))) {
                throw ValidationException::withMessages(['user_dni' => 'La cuenta debe tener el rol correspondiente al vínculo.']);
            }
            $fields = ['is_active' => (bool) $data['is_active'], 'updated_at' => now()];
            if ($data['type'] === 'guardian') {
                $student = Student::visibleInSchoolYears()->where('dni', $data['student_dni'])->firstOrFail();
                $keys = ['user_id' => $user->id, 'student_id' => $student->id];
                $table = 'guardian_student';
                $fields += ['relationship' => $data['relationship'], 'verified_by' => $actor->id, 'verified_at' => now()];
                $subject = $student;
            } else {
                $year = SchoolYear::whereKey($data['school_year_id'])->lockForUpdate()->firstOrFail();
                if ($year->closed_at) {
                    throw ValidationException::withMessages(['school_year_id' => 'Reabre el año antes de cambiar las asignaciones.']);
                }
                $keys = ['user_id' => $user->id, 'grade_id' => $data['grade_id'], 'school_year_id' => $data['school_year_id']];
                $table = 'assistant_assignments';
                $subject = $user;
            }
            $before = DB::table($table)->where($keys)->first();
            if (! $before) {
                $fields['created_at'] = now();
            }
            DB::table($table)->updateOrInsert($keys, $fields);
            app(AuditService::class)->record('assignment.'.$data['type'], $subject, ['before' => $before, 'after' => $keys + $fields]);
        });
    }
}
