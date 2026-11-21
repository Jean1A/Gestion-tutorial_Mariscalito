<?php

namespace App\Jobs;

use App\Models\Section;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\SchoolYearVisibility;
use App\Services\SpreadsheetRows;
use App\Services\StudentService;
use App\Services\StudentWorkbookImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ImportStudents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $importId)
    {
        $this->afterCommit();
    }

    public function handle(SpreadsheetRows $reader, StudentService $students): void
    {
        $import = StudentImport::withoutGlobalScope(SchoolYearVisibility::SCOPE)->findOrFail($this->importId);
        if ($import->status === 'completed') {
            return;
        }
        $actor = User::find($import->user_id);
        if (! $actor || ! $actor->is_active || ! $actor->roles()->whereHas('permissions', fn ($q) => $q->where('name', 'students.manage'))->exists()) {
            throw new RuntimeException('La cuenta que solicitó la importación ya no está autorizada.');
        }
        if ($import->school_year_id !== null) {
            app(StudentWorkbookImport::class)->process($import, $actor);

            return;
        }
        $section = Section::with('schoolYear')->find($import->section_id);
        if (! $section) {
            $import->update(['status' => 'failed', 'failure_message' => 'La sección ya no está disponible. Reabre el año escolar antes de importar.']);

            return;
        }
        $import->update(['status' => 'processing', 'failure_message' => null]);
        $header = null;
        $number = 0;
        foreach ($reader->read(Storage::disk('local')->path($import->path), $import->format) as $values) {
            $number++;
            if ($header === null) {
                $header = array_map(fn ($value) => trim(ltrim((string) $value, "\xEF\xBB\xBF")), $values);
                if ($header !== ['dni', 'first_name', 'last_name', 'birth_date']) {
                    throw new RuntimeException('Encabezado inválido. Usa la plantilla con dni,first_name,last_name,birth_date.');
                }

                continue;
            }
            if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0 || DB::table('student_import_rows')->where('student_import_id', $import->id)->where('row_number', $number)->exists()) {
                continue;
            }
            DB::transaction(function () use ($import, $values, $header, $number, $students, $section) {
                $import = StudentImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                if (DB::table('student_import_rows')->where('student_import_id', $import->id)->where('row_number', $number)->exists()) {
                    return;
                }
                $error = null;
                try {
                    if (count($values) > 4) {
                        throw ValidationException::withMessages(['file' => 'La fila tiene más columnas de las permitidas.']);
                    }
                    $data = array_combine($header, array_pad($values, 4, null));
                    $data = array_map(fn ($v) => $v === null || $v === '' ? null : trim((string) $v), $data);
                    $data['dni'] = (string) ($data['dni'] ?? '');
                    Validator::make($data, ['dni' => ['required', 'regex:/^[0-9]{8}$/'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:140'], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']])->validate();
                    $existing = Student::withTrashed()->where('dni', $data['dni'])->first();
                    if ($existing && ($existing->trashed() || ! $existing->is_active)) {
                        throw ValidationException::withMessages(['dni' => 'El DNI corresponde a un estudiante inactivo. Revisa y reactiva su ficha manualmente.']);
                    }
                    if ($existing && ($existing->first_name !== $data['first_name'] || $existing->last_name !== $data['last_name'] || $existing->birth_date?->toDateString() !== $data['birth_date'])) {
                        throw ValidationException::withMessages(['dni' => 'El DNI existente tiene datos distintos. Revisa la ficha manualmente.']);
                    }
                    if ($existing && $existing->enrollments()->where('school_year_id', $section->school_year_id)->where('section_id', '!=', $section->id)->exists()) {
                        throw ValidationException::withMessages(['section_id' => 'El estudiante ya tiene matrícula en otra sección de este año. Gestiona el traslado manualmente.']);
                    }
                    $students->save($data + ['is_active' => true, 'section_id' => $section->id, 'status' => 'active', 'starts_on' => $section->schoolYear->starts_on->toDateString(), 'ends_on' => null], $existing, $import->user_id);
                } catch (ValidationException $exception) {
                    $error = mb_substr(implode(' ', array_merge(...array_values($exception->errors()))), 0, 500);
                }
                DB::table('student_import_rows')->insert(['student_import_id' => $import->id, 'row_number' => $number, 'succeeded' => $error === null, 'error' => $error]);
                $import->increment($error === null ? 'imported_count' : 'error_count');
            });
        }
        if ($header === null) {
            throw new RuntimeException('El archivo está vacío.');
        }
        $import->update(['status' => 'completed']);
    }

    public function failed(?Throwable $exception): void
    {
        StudentImport::withoutGlobalScope(SchoolYearVisibility::SCOPE)->whereKey($this->importId)->update(['status' => 'failed', 'failure_message' => 'La importación no pudo completarse. Revisa el formato, las asignaciones y el trabajador de colas.']);
    }
}
