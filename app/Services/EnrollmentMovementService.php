<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EnrollmentMovementService
{
    public function __construct(private StudentService $students, private AuditService $audit) {}

    /** @return Collection<int, Enrollment> */
    public function roster(Section $source): Collection
    {
        return $source->enrollments()->with('student')
            ->where('status', 'active')->whereHas('student', fn ($query) => $query->where('is_active', true))
            ->get()->sortBy(fn (Enrollment $enrollment): string => $enrollment->student->full_name);
    }

    public function move(array $data, User $actor): array
    {
        abort_unless($actor->can('manage-academics') && $actor->permits('students.manage'), 403);

        return DB::transaction(function () use ($data, $actor): array {
            $sectionIds = array_unique([$data['source_id'], $data['destination_id'], ...array_filter($data['overrides'] ?? [])]);
            $sections = Section::with('grade.educationLevel')->whereKey($sectionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $years = SchoolYear::whereKey($sections->pluck('school_year_id')->unique()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $sections->get($data['source_id']);
            $destination = $sections->get($data['destination_id']);
            if (! $source || ! $destination || $source->id === $destination->id) {
                throw ValidationException::withMessages(['destination_id' => 'Elige una sección diferente a la de origen.']);
            }

            $originYear = $years->get($source->school_year_id);
            $destinationYear = $years->get($destination->school_year_id);
            $isTransfer = $originYear->id === $destinationYear->id;
            if ($originYear->closed_at || $destinationYear->closed_at || $destinationYear->year < $originYear->year || ($isTransfer && ! $destinationYear->is_active)
                || $data['starts_on'] < $destinationYear->starts_on->toDateString() || $data['starts_on'] > $destinationYear->ends_on->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => 'Elige una fecha válida de un año abierto o en preparación. Los traslados requieren el año activo.']);
            }

            $enrollments = $source->enrollments()->with('student')->whereKey($data['enrollments'])->orderBy('student_id')->lockForUpdate()->get();
            if ($enrollments->count() !== count($data['enrollments'])) {
                throw ValidationException::withMessages(['enrollments' => 'Revisa el lote. Hay matrículas ajenas a la sección de origen.']);
            }
            $applied = [];
            $alreadyProcessed = 0;
            foreach ($enrollments as $enrollment) {
                $targetId = ($data['overrides'][$enrollment->id] ?? null) ?: $destination->id;
                $target = $sections->get($targetId);
                if (! $target || $target->school_year_id !== $destinationYear->id || $target->id === $source->id) {
                    throw ValidationException::withMessages(['overrides' => 'Los destinos individuales deben pertenecer al año de destino y ser distintos del origen.']);
                }
                if ($enrollment->student && $enrollment->student->enrollments()
                    ->where('section_id', $target->id)->whereDate('starts_on', $data['starts_on'])->where('status', 'active')->exists()) {
                    $alreadyProcessed++;

                    continue;
                }
                if (! $enrollment->student?->is_active || $enrollment->status !== 'active') {
                    throw ValidationException::withMessages(['enrollments' => 'Una matrícula seleccionada ya no está activa. Revisa el lote.']);
                }
                if ($enrollment->student->enrollments()->where('school_year_id', $target->school_year_id)
                    ->when($isTransfer, fn ($query) => $query->whereKeyNot($enrollment->id))
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $data['starts_on']))->exists()) {
                    throw ValidationException::withMessages(['enrollments' => $enrollment->student->full_name.' ya tiene una matrícula incompatible en el destino.']);
                }
                $this->students->save([
                    'section_id' => $target->id,
                    'starts_on' => $data['starts_on'],
                    'status' => 'active',
                ], $enrollment->student, $actor->id, allowPreparation: ! $isTransfer);
                $applied[] = ['source_enrollment_id' => $enrollment->id, 'destination_section_id' => $target->id];
            }

            if ($applied !== []) {
                $this->audit->record('enrollment.batch_moved', $source, [
                    'batch' => (string) Str::uuid(),
                    'type' => $isTransfer ? 'transfer' : 'promotion',
                    'starts_on' => $data['starts_on'],
                    'reason' => $data['reason'],
                    'movements' => $applied,
                ], $actor->id);
            }

            return ['applied' => count($applied), 'already_processed' => $alreadyProcessed];
        }, 3);
    }
}
