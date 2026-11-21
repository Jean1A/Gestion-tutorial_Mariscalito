<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;
use RuntimeException;

class StudentWorkbookImport
{
    public const MAX_ROWS = 100;

    public const HEADERS = [
        'NOMBRES', 'APELLIDOS', 'DNI', 'GRADO', 'SECCIÓN',
        'NOMBRE DE APODERADO', 'APELLIDOS DE APODERADO', 'DNI DE APODERADO',
    ];

    private const FIELDS = [
        'first_name', 'last_name', 'dni', 'grade', 'section',
        'guardian_first_name', 'guardian_last_name', 'guardian_dni',
    ];

    public function __construct(
        private SpreadsheetRows $reader,
        private StudentService $students,
        private UserService $users,
        private AssignmentService $assignments,
    ) {}

    public function process(StudentImport $import, User $actor): void
    {
        if (! $actor->can('create', Student::class) || ! $actor->can('create', User::class) || ! $actor->can('manage-assignments')) {
            $import->update(['status' => 'failed', 'failure_message' => 'La cuenta que envió el archivo ya no tiene autorización para crear y vincular usuarios.']);

            return;
        }

        if (! SchoolYear::available()->whereKey($import->school_year_id)->exists()) {
            $import->update(['status' => 'failed', 'failure_message' => 'El año escolar está cerrado. Reábrelo antes de importar.']);

            return;
        }

        try {
            $rows = $this->rows(Storage::disk('local')->path($import->path));
        } catch (ValidationException $exception) {
            $import->update(['status' => 'failed', 'failure_message' => $exception->errors()['file'][0]]);

            return;
        } catch (OpenSpoutException|RuntimeException) {
            $import->update(['status' => 'failed', 'failure_message' => 'No se pudo leer el archivo. Usa la plantilla de Estudiantes en formato Excel .xlsx.']);

            return;
        }

        $import->update(['status' => 'processing', 'failure_message' => null]);
        foreach ($rows as $number => $values) {
            DB::transaction(function () use ($import, $actor, $number, $values): void {
                $batch = StudentImport::withoutGlobalScopes()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if (DB::table('student_import_rows')->where('student_import_id', $batch->id)->where('row_number', $number)->exists()) {
                    return;
                }

                $error = null;
                $access = [];
                try {
                    $access = DB::transaction(fn (): array => $this->saveRow($batch, $values, $actor));
                } catch (ValidationException $exception) {
                    $error = mb_substr(implode(' ', array_merge(...array_values($exception->errors()))), 0, 500);
                } catch (UniqueConstraintViolationException) {
                    $error = 'Uno de los DNI ya fue registrado. Verifica los datos y vuelve a importar la fila.';
                }
                DB::table('student_import_rows')->insert([
                    'student_import_id' => $batch->id, 'row_number' => $number, 'succeeded' => $error === null,
                    'error' => $error, 'access_payload' => $access ? Crypt::encryptString(json_encode($access, JSON_THROW_ON_ERROR)) : null,
                ]);
                $batch->increment($error === null ? 'imported_count' : 'error_count');
            }, 3);
        }

        $import->update(['status' => 'completed']);
    }

    /** @return array<int, list<string>> */
    private function rows(string $path): array
    {
        $rows = [];
        foreach ($this->reader->read($path, 'xlsx', preserveEmptyRows: true) as $index => $cells) {
            $values = array_map(fn (mixed $cell): string => Str::squish((string) $cell), $cells);
            if ($index === 0) {
                $header = fn (string $value): string => Str::ascii($this->normalize($value));
                if (array_map($header, $values) !== array_map($header, self::HEADERS)) {
                    $this->invalid('Encabezado incorrecto. Descarga la plantilla de Estudiantes y conserva sus ocho columnas.');
                }

                continue;
            }
            if ($index > self::MAX_ROWS) {
                $this->invalid('La plantilla admite hasta '.self::MAX_ROWS.' filas de datos por archivo.');
            }
            if (array_filter($values, fn (string $value): bool => $value !== '') !== []) {
                $rows[$index + 1] = $values;
            }
        }
        if ($rows === []) {
            $this->invalid('Agrega al menos una fila completa debajo del encabezado.');
        }

        return $rows;
    }

    /** @return list<array{id:int, version:int, role:string, name:string, dni:string, password:string}> */
    private function saveRow(StudentImport $import, array $values, User $actor): array
    {
        if (count($values) > count(self::FIELDS)) {
            $this->invalid('La fila tiene más de ocho columnas.');
        }
        $data = array_combine(self::FIELDS, array_pad($values, count(self::FIELDS), ''));
        Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:140'],
            'dni' => ['required', 'regex:/^[0-9]{8}$/'], 'grade' => ['required', 'string', 'max:60'],
            'section' => ['required', 'string', 'max:40'], 'guardian_first_name' => ['required', 'string', 'max:100'],
            'guardian_last_name' => ['required', 'string', 'max:140'],
            'guardian_dni' => ['required', 'regex:/^[0-9]{8}$/', 'different:dni'],
        ], [], [
            'first_name' => 'nombres', 'last_name' => 'apellidos', 'dni' => 'DNI del estudiante',
            'grade' => 'grado', 'section' => 'sección', 'guardian_first_name' => 'nombres del apoderado',
            'guardian_last_name' => 'apellidos del apoderado', 'guardian_dni' => 'DNI del apoderado',
        ])->validate();

        $year = SchoolYear::available()->whereKey($import->school_year_id)->lockForUpdate()->first();
        if (! $year) {
            $this->invalid('El año escolar fue cerrado. Reábrelo antes de importar.');
        }
        $sections = Section::with('grade')->where('school_year_id', $year->id)->where('is_active', true)
            ->whereHas('grade', fn ($grades) => $grades->where('education_level_id', $import->education_level_id)
                ->where('is_active', true)->whereHas('educationLevel', fn ($levels) => $levels->where('is_active', true)))
            ->get()->filter(fn (Section $section): bool => $this->normalize($section->grade->name) === $this->normalize($data['grade'])
                && $this->normalize($section->name) === $this->normalize($data['section']));
        if ($sections->count() !== 1) {
            $this->invalid('El grado y la sección no identifican una sección habilitada del año y nivel elegidos.');
        }
        $section = $sections->first();

        $student = Student::withTrashed()->where('dni', $data['dni'])->lockForUpdate()->first();
        if ($student && ($student->trashed() || ! $student->is_active
            || $this->normalize($student->first_name) !== $this->normalize($data['first_name'])
            || $this->normalize($student->last_name) !== $this->normalize($data['last_name']))) {
            $this->invalid('El DNI del estudiante ya existe con otros nombres o corresponde a una ficha inactiva. Revisa su ficha.');
        }
        $enrollments = $student?->enrollments()->where('school_year_id', $year->id)->get() ?? collect();
        $current = $enrollments->first(fn ($enrollment): bool => $enrollment->status === 'active' && $enrollment->ends_on === null);
        if ($enrollments->isNotEmpty() && $current?->section_id !== $section->id) {
            $this->invalid('El estudiante tiene otra matrícula o una baja en ese año. Revisa su ficha para gestionar el traslado o la reactivación.');
        }
        if (! $current) {
            $student = $this->students->save([
                'dni' => $data['dni'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'is_active' => true, 'section_id' => $section->id, 'status' => 'active',
                'starts_on' => $year->starts_on->toDateString(), 'ends_on' => null,
            ], $student, $actor->id, allowPreparation: true);
        }

        $access = $this->studentAccess($student, $actor);
        $guardian = User::withTrashed()->where('dni', $data['guardian_dni'])->lockForUpdate()->first();
        $guardianName = $data['guardian_first_name'].' '.$data['guardian_last_name'];
        if (Str::length($guardianName) > 150) {
            $this->invalid('El nombre completo del apoderado debe tener hasta 150 caracteres.');
        }
        if ($guardian && (! $guardian->hasRole(RoleName::Guardian) || ! $actor->can('update', $guardian)
            || $this->normalize($guardian->name) !== $this->normalize($guardianName))) {
            $this->invalid('El DNI del apoderado corresponde a una cuenta retirada, a otro perfil o a un nombre distinto. Revisa Gestión de usuarios.');
        }
        if (! $guardian) {
            $guardian = $this->users->save([
                'name' => $guardianName, 'dni' => $data['guardian_dni'], 'password' => $data['guardian_dni'],
                'roles' => [Role::where('name', RoleName::Guardian->value)->sole()->id],
            ], actorId: $actor->id);
            $access[] = $this->access($guardian, RoleName::Guardian);
        }

        $link = $guardian->students()->whereKey($student->id)->first()?->pivot;
        if ($link && ! $link->is_active) {
            $this->invalid('El vínculo con este apoderado fue retirado. Verifica el vínculo manualmente antes de reactivarlo.');
        }
        if (! $link?->verified_at) {
            $this->assignments->save([
                'type' => RoleName::Guardian->value, 'user_dni' => $guardian->dni, 'student_dni' => $student->dni,
                'relationship' => $link?->relationship ?? 'Apoderado', 'is_active' => true,
            ], $actor);
        }

        return $access;
    }

    private function studentAccess(Student $student, User $actor): array
    {
        $account = User::withTrashed()->where('dni', $student->dni)->lockForUpdate()->first();
        if ($account) {
            if ($account->student_profile_id !== $student->id || $account->trashed() || ! $account->roles()->where('name', RoleName::Student->value)->exists()) {
                $this->invalid('El DNI del estudiante está asociado a otra cuenta. Revisa el acceso en su ficha.');
            }

            return [];
        }
        if (User::withTrashed()->where('student_profile_id', $student->id)->exists()) {
            $this->invalid('La ficha del estudiante ya tiene un acceso con otro DNI. Corrígelo antes de importar.');
        }

        $account = $this->users->saveStudentAccess($student, $student->dni, actorId: $actor->id);

        return [$this->access($account, RoleName::Student)];
    }

    private function access(User $account, RoleName $role): array
    {
        return [
            'id' => $account->id, 'version' => $account->session_version ?? 1, 'role' => $role->value,
            'name' => $account->name, 'dni' => $account->dni, 'password' => $account->dni,
        ];
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::squish($text));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
