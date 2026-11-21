<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Exception\OpenSpoutException;

class AccountImportService
{
    public const MAX_ROWS = 100;

    public const COLUMNS = ['nombres_apellidos', 'dni', 'contrasena_temporal', 'nivel', 'grado', 'seccion'];

    public function __construct(
        private SpreadsheetRows $spreadsheets,
        private UserService $users,
        private TutorCoordinationService $coordination,
    ) {}

    /** @return array{accounts: int, links: int} */
    public function import(UploadedFile $file, int $yearId, User $actor): array
    {
        Gate::forUser($actor)->authorize('create', User::class);
        Gate::forUser($actor)->authorize('manage-assignments');
        Gate::forUser($actor)->authorize('coordinate-tutors');

        $rows = $this->readRows($file);

        return DB::transaction(function () use ($rows, $yearId, $actor): array {
            $year = SchoolYear::available()->lockForUpdate()->find($yearId);
            if (! $year) {
                throw ValidationException::withMessages(['school_year_id' => 'Elige un año escolar abierto o en preparación.']);
            }
            $roleId = Role::where('name', RoleName::Tutor->value)->firstOrFail()->id;
            $sections = Section::with('grade.educationLevel')->where('school_year_id', $year->id)->where('is_active', true)->get();
            $accounts = 0;
            $links = 0;
            foreach ($rows as $row => $data) {
                $user = User::withTrashed()->where('dni', $data['dni'])->lockForUpdate()->first();
                if ($user) {
                    if (! $user->hasRole(RoleName::Tutor) || ! $actor->can('update', $user)
                        || Str::lower(Str::squish($user->name)) !== Str::lower($data['nombres_apellidos'])) {
                        $this->invalidRow($row, 'El DNI pertenece a otra cuenta o está retirado. Revisa el nombre y el rol en Gestión de usuarios.');
                    }
                } else {
                    $password = Validator::make(['password' => $data['contrasena_temporal']], [
                        'password' => ['required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()],
                    ]);
                    if ($password->fails() || strlen($data['contrasena_temporal']) > 72) {
                        $this->invalidRow($row, 'La contraseña temporal debe tener entre 12 y 72 caracteres, con mayúsculas, minúsculas y números.');
                    }
                    $user = $this->users->save(['name' => $data['nombres_apellidos'], 'dni' => $data['dni'], 'password' => $data['contrasena_temporal'], 'roles' => [$roleId]]);
                    $accounts++;
                }

                $links += (int) $this->linkTutor($user, $sections, $data, $row, $actor);
            }

            return compact('accounts', 'links');
        }, 3);
    }

    /** @return array<int, array<string, string>> */
    private function readRows(UploadedFile $file): array
    {
        $columns = self::COLUMNS;
        $rows = [];
        $headerFound = false;
        try {
            foreach ($this->spreadsheets->read($file->getRealPath(), 'xlsx', preserveEmptyRows: true) as $index => $cells) {
                $values = array_map(fn (mixed $cell): string => trim((string) $cell), $cells);
                $row = $index + 1;
                if (! $headerFound) {
                    if ($values !== $columns) {
                        $this->invalidRow($row, 'Usa la plantilla de este rol y conserva los encabezados.');
                    }
                    $headerFound = true;

                    continue;
                }
                if ($row > self::MAX_ROWS + 1) {
                    $this->invalidRow($row, 'El máximo es de '.self::MAX_ROWS.' filas por archivo.');
                }
                if (! array_filter($values, fn (string $value): bool => $value !== '')) {
                    continue;
                }
                if (count($values) > count($columns)) {
                    $this->invalidRow($row, 'Elimina las columnas adicionales.');
                }
                $data = array_combine($columns, array_pad($values, count($columns), ''));
                $data['nombres_apellidos'] = Str::squish($data['nombres_apellidos']);
                if ($data['nombres_apellidos'] === '' || Str::length($data['nombres_apellidos']) > 150 || ! preg_match('/^[0-9]{8}$/', $data['dni'])) {
                    $this->invalidRow($row, 'Completa el nombre y un DNI de 8 dígitos. Usa formato texto para conservar ceros iniciales.');
                }
                $rows[$row] = $data;
            }
        } catch (OpenSpoutException|\RuntimeException) {
            throw ValidationException::withMessages(['file' => 'El archivo no se pudo leer. Guarda una copia como Excel (.xlsx) e inténtalo de nuevo.']);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo debe contener al menos una fila de datos.']);
        }

        return $rows;
    }

    /** @param Collection<int, Section> $sections */
    private function linkTutor(User $tutor, Collection $sections, array $data, int $row, User $actor): bool
    {
        $section = $sections->first(fn (Section $section): bool => $section->grade->is_active && $section->grade->educationLevel->is_active
            && Str::lower($section->grade->educationLevel->name) === Str::lower($data['nivel'])
            && Str::lower($section->grade->name) === Str::lower($data['grado'])
            && Str::lower($section->name) === Str::lower($data['seccion']));
        if (! $section) {
            $this->invalidRow($row, 'El nivel, grado y sección no coinciden con una sección habilitada del año elegido.');
        }

        $section = Section::whereKey($section->id)->lockForUpdate()->firstOrFail();
        $responsible = $section->tutors()->wherePivot('is_active', true)->where('users.is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', RoleName::Tutor->value));
        if ((clone $responsible)->where('users.id', '!=', $tutor->id)->exists()) {
            $this->invalidRow($row, 'La sección ya tiene tutor. Si necesitas reemplazarlo, hazlo desde su ficha o desde Asignaciones.');
        }
        if ($responsible->where('users.id', $tutor->id)->exists()) {
            return false;
        }

        $this->coordination->saveAssignment($section, ['tutor_id' => $tutor->id, 'is_active' => true], $actor);

        return true;
    }

    private function invalidRow(int $row, string $message): never
    {
        throw ValidationException::withMessages(['file' => 'Fila '.$row.'. '.$message.' No se guardó ninguna fila.']);
    }
}
