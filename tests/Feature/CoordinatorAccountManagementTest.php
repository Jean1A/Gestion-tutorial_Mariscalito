<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Section;
use App\Models\Student;
use App\Models\SupportRecord;
use App\Models\User;
use App\Notifications\SchoolNotification;
use App\Services\ConsentService;
use App\Services\SpreadsheetRows;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class CoordinatorAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
    }

    public function test_coordinator_uses_role_cards_and_can_retire_and_reactivate_an_account(): void
    {
        $this->login($this->user(RoleName::Coordinator));
        $tutor = $this->user(RoleName::Tutor, 'Tutor de prueba');
        $guardian = $this->user(RoleName::Guardian, 'Apoderado de prueba');

        $this->get(route('dashboard'))->assertOk()->assertSeeInOrder([
            'Panel general', 'Gestión del colegio', 'Gestión de usuarios', 'Estructura académica',
            'Año escolar', 'Reportes', 'Seguimientos y alertas', 'Intervenciones', 'Tutoría grupal', 'Escuela de padres', 'Notificaciones',
        ]);
        $this->get(route('users.index'))->assertOk()->assertViewIs('users.roles')
            ->assertSeeInOrder(['Administrar tutores', 'Administrar docentes', 'Administrar auxiliares', 'Administrar apoderados', 'Administrar estudiantes'])
            ->assertDontSee($tutor->name)->assertDontSee($guardian->name);
        $this->get(route('users.index', ['role' => 'tutor']))->assertOk()
            ->assertSee('Crear tutor')->assertSee('Descargar formato Excel')->assertSee('Importar tutores')
            ->assertSee('Grado y sección')->assertSee('Activa')->assertSee($tutor->name)->assertDontSee($guardian->name);
        $this->get(route('users.index', ['role' => 'guardian']))->assertOk()
            ->assertSee('Crear apoderado')->assertDontSee('Descargar formato Excel')->assertDontSee('Importar apoderados')
            ->assertSee($guardian->name)->assertDontSee($tutor->name);
        $this->get(route('users.edit', [$tutor, 'role' => 'tutor']))->assertOk()->assertSee('Restablecer contraseña')->assertSee('Secciones del tutor');
        $this->get(route('users.edit', [$guardian, 'role' => 'guardian']))->assertOk()->assertSee('Restablecer contraseña')->assertSee('Estudiantes vinculados');

        $this->patch(route('users.status', [$tutor, 'role' => 'tutor']), ['is_active' => false])->assertRedirect(route('users.index', ['role' => 'tutor']));
        $this->get(route('users.index', ['role' => 'tutor']))->assertOk()->assertDontSee($tutor->name);
        $this->get(route('users.index', ['role' => 'tutor', 'state' => 'retired']))->assertOk()->assertSee($tutor->name)->assertSee('Reactivar');
        $this->patch(route('users.status', $tutor), ['is_active' => true])->assertRedirect();
        $this->assertTrue($tutor->fresh()->is_active);

        $guardianRole = ['role' => 'guardian'];
        $this->patch(route('users.status', [$guardian, ...$guardianRole]), ['is_active' => false])
            ->assertRedirect(route('users.index', $guardianRole));
        $this->get(route('users.index', $guardianRole))->assertOk()->assertDontSee($guardian->name);
        $this->assertFalse($guardian->fresh()->is_active);
    }

    public function test_tutor_excel_creates_accounts_and_assigns_sections_without_resetting_or_replacing_existing_access(): void
    {
        $this->login($this->user(RoleName::Coordinator));
        $section = CourseOffering::factory()->create()->section;
        $columns = ['nombres_apellidos', 'dni', 'contrasena_temporal', 'nivel', 'grado', 'seccion'];
        $location = [$section->grade->educationLevel->name, $section->grade->name, $section->name];
        $row = ['Tutor de importación', '01234567', 'PruebaTemporal123', ...$location];
        $year = ['school_year_id' => $section->school_year_id];
        $store = route('users.import.store', 'tutor');

        $this->get(route('users.import.create', 'tutor'))->assertOk()->assertSee('Importar tutores desde Excel');
        $template = $this->get(route('users.import.template', 'tutor'))->assertOk()->assertDownload('plantilla-tutores.xlsx')->getFile();
        $templateRows = app(SpreadsheetRows::class)->read($template->getPathname(), 'xlsx');
        $this->assertSame($columns, $templateRows->current());
        $this->post($store, $year + ['file' => UploadedFile::fake()->createWithContent($template->getFilename(), $template->getContent())])
            ->assertSessionHasErrors(['file' => 'El archivo debe contener al menos una fila de datos.']);
        $this->post($store, $year + ['file' => $this->workbook([$templateRows->current(), $row])])
            ->assertSessionHasNoErrors()->assertRedirect(route('users.index', ['role' => 'tutor']));
        $tutor = User::where('dni', '01234567')->sole();
        $this->assertTrue($tutor->hasRole(RoleName::Tutor));
        $this->assertTrue(Hash::check('PruebaTemporal123', $tutor->password));
        $this->assertTrue($tutor->must_change_password);
        $this->assertDatabaseHas('tutor_assignments', ['user_id' => $tutor->id, 'section_id' => $section->id, 'is_active' => true]);
        $this->assertStringNotContainsString('PruebaTemporal123', DB::table('audit_logs')->pluck('changes')->join(' '));

        $row[2] = 'DistintaTemporal123';
        $this->post(route('users.import.store', 'tutor'), ['school_year_id' => $section->school_year_id, 'file' => $this->workbook([$columns, $row])])
            ->assertSessionHas('status', '0 cuentas creadas y 0 vínculos registrados.');
        $this->assertSame($tutor->password, $tutor->fresh()->password);

        $other = Section::create(['school_year_id' => $section->school_year_id, 'grade_id' => $section->grade_id, 'name' => 'Otra']);
        $directory = ['role' => 'tutor'] + $year;
        $this->get(route('users.index', $directory + ['section_id' => $section->id]))->assertOk()->assertSee($tutor->name);
        $this->get(route('users.index', $directory + ['section_id' => $other->id]))->assertOk()->assertDontSee($tutor->name);
        $this->post(route('users.import.store', 'tutor'), [
            'school_year_id' => $section->school_year_id,
            'file' => $this->workbook([
                $columns,
                ['Tutor de otra sección', '01234568', 'PruebaTemporal123', $location[0], $location[1], $other->name],
                ['Tutor que colisiona', '01234569', 'PruebaTemporal123', ...$location],
            ]),
        ])->assertSessionHasErrors(['file' => 'Fila 3. La sección ya tiene tutor. Si necesitas reemplazarlo, hazlo desde su ficha o desde Asignaciones. No se guardó ninguna fila.']);
        $this->assertDatabaseMissing('users', ['dni' => '01234568']);
        $this->assertDatabaseMissing('users', ['dni' => '01234569']);
        $this->assertDatabaseCount('tutor_assignments', 1);

        $this->patch(route('users.status', $tutor), ['is_active' => false])->assertRedirect();
        $this->post($store, $year + ['file' => $this->workbook([$columns, $row])])
            ->assertSessionHasErrors(['file' => 'Fila 2. El DNI pertenece a otra cuenta o está retirado. Revisa el nombre y el rol en Gestión de usuarios. No se guardó ninguna fila.']);
        $this->assertFalse($tutor->fresh()->is_active);
    }

    public function test_guardian_workbooks_can_only_be_imported_from_students(): void
    {
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('users.import.template', 'guardian'))->assertNotFound();
        $this->post(route('users.import.store', 'guardian'))->assertNotFound();
        $this->login($this->user(RoleName::Teacher));
        $this->get(route('imports.template'))->assertForbidden();
        $this->post(route('imports.store'))->assertForbidden();
    }

    public function test_closed_year_disappears_from_directory_reports_and_linked_portals_until_reopening(): void
    {
        $coordinator = $this->user(RoleName::Coordinator);
        $guardian = $this->user(RoleName::Guardian);
        $tutor = $this->user(RoleName::Tutor, 'Tutor del año de prueba');
        $offering = CourseOffering::factory()->create();
        $year = $offering->section->schoolYear;
        $tutor->tutoredSections()->attach($offering->section_id, ['is_active' => true]);
        $directory = ['role' => 'tutor', 'section_id' => $offering->section_id];
        $closedStudent = $this->enroll($offering->section);
        $guardian->students()->attach($closedStudent->id, ['is_active' => true, 'relationship' => 'Apoderado', 'verified_by' => $coordinator->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($guardian);
        $record = SupportRecord::factory()->create(['section_id' => $offering->section_id, 'student_id' => $closedStudent->id]);
        $guardian->notifications()->create(['id' => Str::uuid(), 'type' => SchoolNotification::class, 'data' => [
            'title' => 'Aviso del año de prueba', 'message' => 'Mensaje de prueba', 'url' => '/portal/home', 'school_year_id' => $year->id,
        ]]);

        $this->login($coordinator);
        $this->get(route('students.index'))->assertOk()->assertSee($closedStudent->last_name);
        $this->get(route('users.index', $directory))->assertOk()->assertSee($tutor->name);
        $this->post(route('academics.years.status', $year), ['action' => 'close', 'reason' => 'Cierre autorizado por el colegio.'])->assertRedirect();
        $this->get(route('academics.index', 'years'))->assertOk()->assertSee('Cerrado');
        $this->get(route('students.index'))->assertOk()->assertDontSee($closedStudent->last_name);
        $this->get(route('users.index', ['role' => 'tutor']))->assertOk()->assertSee($tutor->name)->assertSee('Sin sección asignada');
        $this->get(route('users.index', $directory))->assertSessionHasErrors('section_id');
        $this->get(route('support.show', $record))->assertNotFound();
        $this->get(route('school-reports.index', ['school_year_id' => $year->id]))->assertSessionHasErrors('school_year_id');
        $this->post(route('imports.store'), ['school_year_id' => $year->id, 'identity_verified' => '1'])->assertSessionHasErrors('school_year_id');
        $this->assertDatabaseHas('enrollments', ['school_year_id' => $year->id, 'student_id' => $closedStudent->id]);

        $this->login($guardian);
        $this->get(route('portal.children'))->assertOk()->assertDontSee($closedStudent->full_name);
        $this->get(route('portal.report.pdf', [$closedStudent, $year]))->assertNotFound();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('Aviso del año de prueba');

        $this->login($coordinator);
        $this->post(route('academics.years.status', $year), ['action' => 'reopen', 'reason' => 'Reapertura autorizada para revisión.'])->assertRedirect();
        $this->get(route('students.index'))->assertOk()->assertSee($closedStudent->last_name);
        $this->get(route('users.index', $directory))->assertOk()->assertSee($tutor->name);
        $this->get(route('support.show', $record))->assertOk();
        $this->login($guardian);
        $this->get(route('portal.children'))->assertOk()->assertSee($closedStudent->full_name);
        $this->get(route('notifications.index'))->assertOk()->assertSee('Aviso del año de prueba');
    }

    private function user(RoleName $role, ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);
        $user->roles()->attach(Role::where('name', $role->value)->sole());

        return $user->refresh();
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }

    private function enroll(Section $section): Student
    {
        $student = Student::factory()->create();
        Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'school_year_id' => $section->school_year_id, 'starts_on' => '2026-03-02']);

        return $student;
    }

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'users-xlsx-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent('usuarios.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
