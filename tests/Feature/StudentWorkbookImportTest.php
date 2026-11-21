<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Jobs\ImportStudents;
use App\Models\CourseOffering;
use App\Models\Grade;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\SpreadsheetRows;
use App\Services\StudentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StudentWorkbookImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(11, 0));
        Storage::fake('local');
        Queue::fake();
    }

    public function test_official_template_imports_siblings_in_different_sections_and_exports_only_new_accesses(): void
    {
        $coordinator = $this->account(RoleName::Coordinator);
        $existing = $this->account(RoleName::Tutor);
        $section = CourseOffering::factory()->create()->section;
        $secondGrade = Grade::create(['education_level_id' => $section->grade->education_level_id, 'name' => 'Segundo']);
        $secondSection = Section::create(['school_year_id' => $section->school_year_id, 'grade_id' => $secondGrade->id, 'name' => 'B']);
        $this->login($coordinator);
        $this->get(route('students.index'))->assertOk()->assertSee('Descargar formato Excel')->assertSee('Importar estudiantes');
        $this->get(route('imports.index'))->assertOk()->assertSee('Nivel educativo')->assertSee('DNI DE APODERADO')->assertDontSee('Sección de destino');
        $template = $this->get(route('imports.template'))->assertOk()->assertDownload('plantilla-estudiantes.xlsx')->getFile();
        $reader = app(SpreadsheetRows::class);
        $templateRows = $reader->read($template->getPathname(), 'xlsx');
        $this->assertSame(['NOMBRES', 'APELLIDOS', 'DNI', 'GRADO', 'SECCIÓN', 'NOMBRE DE APODERADO', 'APELLIDOS DE APODERADO', 'DNI DE APODERADO'], $templateRows->current());

        $location = [$section->grade->name, $section->name];
        $family = ['Camila', 'Pérez', '01234569'];
        $data = [
            $templateRows->current(),
            ['Ada', 'Pérez', '01234567', ...$location, ...$family],
            ['Ana', 'Pérez', '01234568', $secondGrade->name, $secondSection->name, ...$family],
            [' '],
            ['Fila', 'Inválida', '01234570', 'Grado inexistente', 'B', ...$family],
            ['Ficha', 'Revertida', '01234571', ...$location, 'Cuenta', 'Ocupada', $existing->dni],
        ];
        $selection = ['school_year_id' => $section->school_year_id, 'education_level_id' => $section->grade->education_level_id];
        $this->post(route('imports.store'), $selection + ['file' => $this->workbook($data)])
            ->assertSessionHasErrors('identity_verified');
        $this->assertDatabaseCount('student_imports', 0);
        $batch = $this->submitWorkbook($selection, $data);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame(2, $batch->error_count);
        $this->assertDatabaseHas('student_import_rows', ['student_import_id' => $batch->id, 'row_number' => 5, 'succeeded' => false]);
        $this->assertDatabaseMissing('students', ['dni' => '01234571']);
        $this->assertDatabaseMissing('users', ['dni' => '01234571']);

        $first = Student::where('dni', '01234567')->sole();
        $second = Student::where('dni', '01234568')->sole();
        $guardian = User::where('dni', '01234569')->sole();
        $this->assertSame($section->id, $first->enrollments()->sole()->section_id);
        $this->assertSame($secondSection->id, $second->enrollments()->sole()->section_id);
        $this->assertSame([$first->id, $second->id], $guardian->students()->wherePivot('is_active', true)
            ->wherePivot('verified_by', $coordinator->id)->wherePivotNotNull('verified_at')->orderBy('students.id')->pluck('students.id')->all());
        $this->get(route('users.index', ['role' => 'guardian']))->assertOk()->assertSee('Camila Pérez')->assertDontSee('Importar apoderados');
        $this->get(route('users.edit', [$guardian, 'role' => 'guardian']))->assertOk()->assertSee($first->full_name)->assertSee($second->full_name);
        $this->get(route('students.index'))->assertOk()->assertSee('Apoderados vinculados')->assertSee('Segundo B');

        $this->get(route('imports.show', $batch))->assertOk()->assertSee('Descargar accesos temporales')->assertSee('El grado y la sección');
        $download = $this->get(route('imports.access', $batch))->assertOk()->assertDownload('accesos-importacion-'.$batch->id.'.xlsx');
        $file = UploadedFile::fake()->createWithContent('accesos.xlsx', $download->streamedContent());
        $access = iterator_to_array($reader->read($file->getPathname(), 'xlsx'));
        $this->assertSame(['Perfil', 'Nombre', 'DNI', 'Contraseña temporal'], array_shift($access));
        $this->assertCount(3, $access);
        foreach ($access as [$roleLabel, $name, $dni, $password]) {
            $account = User::where('dni', $dni)->sole();
            $this->assertSame($account->defaultRole()->label(), $roleLabel);
            $this->assertTrue($account->must_change_password);
            $this->assertSame($dni, $password);
            $this->assertTrue(Hash::check($password, $account->password));
        }
        $this->assertStringNotContainsString('"password"', DB::table('audit_logs')->pluck('changes')->join(' '));
        $this->assertStringNotContainsString('"password"', DB::table('student_import_rows')->pluck('access_payload')->join(' '));

        $guardianHash = $guardian->password;
        $repeat = $this->submitWorkbook($selection, array_slice($data, 0, 3));
        $this->assertSame(2, $repeat->imported_count);
        $this->assertSame($guardianHash, $guardian->fresh()->password);
        $this->assertDatabaseCount('guardian_student', 2);
        $this->get(route('imports.show', $repeat))->assertOk()->assertDontSee('Descargar accesos temporales');
        $this->get(route('imports.access', $repeat))->assertNotFound();

        $this->login($this->account(RoleName::Administrator));
        $this->get(route('imports.access', $batch))->assertForbidden();
        $this->travel(25)->hours();
        $this->login($coordinator);
        $this->get(route('imports.access', $batch))->assertForbidden();

        $studentAccount = User::where('student_profile_id', $first->id)->sole();
        foreach ([$studentAccount, $guardian] as $account) {
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->post(route('login.store', $account->defaultRole()->value), ['dni' => $account->dni, 'password' => $account->dni])
                ->assertRedirect(route('password.change'));
            $this->get(route('dashboard'))->assertRedirect(route('password.change'));
            $this->post(route('password.change.store'), [
                'current_password' => $account->dni, 'password' => 'MiPrimeraClave42', 'password_confirmation' => 'MiPrimeraClave42',
            ])->assertRedirect(route('dashboard'));
            $this->assertFalse(Hash::check($account->dni, $account->fresh()->password));
        }
        app(ConsentService::class)->accept($guardian->fresh());
        $this->get(route('portal.children'))->assertOk()->assertSee($first->full_name)->assertSee($second->full_name);
    }

    public function test_closed_year_rejects_a_batch_waiting_in_the_queue(): void
    {
        $coordinator = $this->account(RoleName::Coordinator);
        $section = CourseOffering::factory()->create()->section;
        $this->login($coordinator);
        $template = $this->get(route('imports.template'))->getFile();
        $rows = app(SpreadsheetRows::class)->read($template->getPathname(), 'xlsx');
        $data = [$rows->current(), ['Luis', 'Vera', '01234572', $section->grade->name, $section->name, 'Lucía', 'Vera', '01234573']];
        $this->post(route('imports.store'), [
            'school_year_id' => $section->school_year_id, 'education_level_id' => $section->grade->education_level_id,
            'identity_verified' => '1', 'file' => $this->workbook($data),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $batch = StudentImport::sole();
        $this->post(route('academics.years.status', $section->schoolYear), ['action' => 'close', 'reason' => 'Cierre para comprobar la importación.'])->assertRedirect();

        (new ImportStudents($batch->id))->handle(app(SpreadsheetRows::class), app(StudentService::class));
        $this->assertDatabaseHas('student_imports', ['id' => $batch->id, 'status' => 'failed']);
        $this->assertDatabaseMissing('students', ['dni' => '01234572']);
        $this->assertDatabaseMissing('users', ['dni' => '01234573']);
        $this->get(route('imports.show', $batch))->assertNotFound();
        $this->get(route('imports.access', $batch))->assertNotFound();
    }

    private function submitWorkbook(array $selection, array $rows): StudentImport
    {
        $response = $this->post(route('imports.store'), $selection + ['identity_verified' => '1', 'file' => $this->workbook($rows)]);
        $batch = StudentImport::latest('id')->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('imports.show', $batch));
        Queue::assertPushed(ImportStudents::class, fn (ImportStudents $job): bool => $job->importId === $batch->id);
        (new ImportStudents($batch->id))->handle(app(SpreadsheetRows::class), app(StudentService::class));

        return $batch->fresh();
    }

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'student-workbook-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row ?: [null]));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent('estudiantes.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    private function account(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->sole());

        return $user->refresh();
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }
}
