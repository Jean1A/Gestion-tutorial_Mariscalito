<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\ConsentService;
use Database\Seeders\ApprovedSettingsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessAndAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([RoleSeeder::class, ApprovedSettingsSeeder::class]);
        Setting::findOrFail('security.idle_minutes')->update(['value' => 7]);
    }

    public function test_login_uses_own_dni_and_coordinator_has_priority_over_tutor(): void
    {
        $user = $this->userWithRoles(RoleName::Coordinator, RoleName::Tutor);
        $response = $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234']);
        $response->assertRedirect('/dashboard')->assertSessionHas('active_role', 'coordinator');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id]);
    }

    public function test_login_shows_enabled_profiles_before_asking_for_credentials(): void
    {
        $panel = $this->get(route('login'))->assertOk()->assertViewIs('auth.profiles')
            ->assertDontSee('name="password"', false)->assertDontSee('Comité TOE');
        foreach (RoleName::cases() as $role) {
            $panel->assertSee(route('login', $role->value))->assertSee($role->label());
        }

        $this->get(route('login', RoleName::Student->value))->assertOk()->assertViewIs('auth.login')
            ->assertSee('Cambiar perfil')->assertSee('name="password"', false)->assertDontSee('Recordar mi DNI');
        $this->get(route('login', RoleName::Guardian->value))->assertOk()->assertSee('Apoderado')->assertSee('Recordar mi DNI');
        $this->assertDatabaseCount('roles', 7);
        foreach (['director', 'psychologist', 'toe_committee'] as $removedProfile) {
            $this->get(route('login', $removedProfile))->assertNotFound();
            $this->post(route('login.store', $removedProfile))->assertNotFound();
        }
    }

    public function test_selected_login_rejects_an_unassigned_profile_and_opens_the_assigned_portal(): void
    {
        $user = $this->userWithRoles(RoleName::Coordinator, RoleName::Tutor);
        $credentials = ['dni' => $user->dni, 'password' => 'ExamplePass1234'];
        $profile = route('login', RoleName::Administrator->value);

        $this->from($profile)->post(route('login.store', RoleName::Administrator->value), $credentials)
            ->assertRedirect($profile)->assertSessionHasErrors('dni');
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.login', 'subject_id' => $user->id]);

        $this->post(route('login.store', RoleName::Tutor->value), $credentials)
            ->assertRedirect(route('dashboard'))->assertSessionHas('active_role', RoleName::Tutor->value);
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))->assertOk()->assertViewIs('advisor.home');
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_guardian_can_only_view_verified_active_links(): void
    {
        $guardian = $this->userWithRoles(RoleName::Guardian);
        $own = Student::factory()->create();
        $other = Student::factory()->create();
        $guardian->students()->attach($own, ['relationship' => 'Madre', 'verified_by' => $guardian->id, 'verified_at' => now(), 'is_active' => true]);
        $this->sessionAs($guardian);
        $this->get('/students/'.$own->id)->assertOk()->assertSee($own->full_name);
        $this->get('/students/'.$other->id)->assertForbidden();
        $guardian->students()->updateExistingPivot($own->id, ['is_active' => false]);
        $this->get('/students/'.$own->id)->assertForbidden();
    }

    public function test_tutor_cannot_access_other_sections_or_students(): void
    {
        $tutor = $this->userWithRoles(RoleName::Tutor);
        [$ownSection, $own] = $this->sectionAndStudent('A');
        [$otherSection, $other] = $this->sectionAndStudent('B');
        DB::table('tutor_assignments')->insert(['user_id' => $tutor->id, 'section_id' => $ownSection->id, 'is_active' => true]);
        $this->sessionAs($tutor);
        $this->get('/students/'.$own->id)->assertOk();
        $this->get('/students/'.$other->id)->assertForbidden();
        $this->assertFalse(Gate::forUser($tutor)->allows('recordAttendance', $otherSection));
    }

    public function test_assistant_scope_is_limited_by_grade_and_school_year(): void
    {
        $assistant = $this->userWithRoles(RoleName::Assistant);
        [$section] = $this->sectionAndStudent('A');
        $otherYear = SchoolYear::create(['year' => 2027, 'starts_on' => '2027-03-01', 'ends_on' => '2027-12-17']);
        $other = Section::create(['school_year_id' => $otherYear->id, 'grade_id' => $section->grade_id, 'name' => 'A']);
        DB::table('assistant_assignments')->insert(['user_id' => $assistant->id, 'grade_id' => $section->grade_id, 'school_year_id' => $section->school_year_id, 'is_active' => true]);
        $this->assertSame([$section->id], app(AccessScope::class)->sections($assistant)->pluck('id')->all());
        $this->assertFalse(Gate::forUser($assistant)->allows('recordAttendance', $other));
    }

    public function test_role_switch_rejects_unassigned_roles_and_applies_tutor_scope(): void
    {
        $coordinator = $this->userWithRoles(RoleName::Coordinator, RoleName::Tutor);
        [$section] = $this->sectionAndStudent('A');
        $this->sessionAs($coordinator);
        $this->post('/active-role', ['role' => 'administrator'])->assertForbidden();
        $this->post('/active-role', ['role' => 'tutor'])->assertRedirect('/dashboard')->assertSessionHas('active_role', 'tutor');
        $this->get('/dashboard')->assertOk()->assertDontSee('Primero A');
    }

    public function test_password_change_is_required_before_sensitive_routes(): void
    {
        $user = $this->userWithRoles(RoleName::Administrator);
        $user->update(['must_change_password' => true]);
        $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234'])->assertRedirect('/password/change');
        $this->get('/users')->assertRedirect('/password/change');
        $this->post('/password/change', ['current_password' => 'ExamplePass1234', 'password' => 'NewExamplePass1234', 'password_confirmation' => 'NewExamplePass1234'])->assertRedirect('/dashboard');
        $this->assertTrue(Hash::check('NewExamplePass1234', $user->fresh()->password));
        $this->get('/users')->assertOk();
    }

    public function test_fifth_failure_starts_a_full_sixty_second_lock_and_displays_the_countdown(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->userWithRoles(RoleName::Student);
        $profile = route('login', RoleName::Student->value);
        $url = route('login.store', RoleName::Student->value);
        $failed = ['dni' => $user->dni, 'password' => 'incorrect'];
        $valid = ['dni' => $user->dni, 'password' => 'ExamplePass1234'];
        for ($i = 0; $i < 4; $i++) {
            $this->from($profile)->post($url, $failed)->assertSessionHasErrors([
                'dni' => 'Verifica tu DNI, contraseña y el perfil elegido.',
            ]);
            $this->travel(20)->seconds();
        }
        $this->from($profile)->post($url, $failed)->assertRedirect($profile)->assertSessionHasErrors([
            'dni' => 'Acceso bloqueado temporalmente. Intenta nuevamente en 60 segundos.',
        ]);
        $this->get($profile)->assertOk()->assertSee('data-login-lockout="60"', false);

        $this->travel(59)->seconds();
        $this->get($profile)->assertOk()->assertSee('data-login-lockout="1"', false);
        $this->post($url, $valid)->assertSessionHasErrors('dni');
        $this->assertGuest();

        $this->travel(1)->seconds();
        $this->get($profile)->assertOk()->assertDontSee('data-login-lockout=', false);
        $this->post($url, $valid)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_lock_is_shared_across_profiles_for_one_dni_and_does_not_affect_another_account_on_the_same_ip(): void
    {
        config(['cache.default' => 'database']);
        $lockedUser = $this->userWithRoles(RoleName::Tutor, RoleName::Coordinator);
        $otherUser = $this->userWithRoles(RoleName::Tutor);
        $tutorLogin = route('login.store', RoleName::Tutor->value);
        $coordinatorLogin = route('login.store', RoleName::Coordinator->value);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);

        for ($i = 0; $i < 5; $i++) {
            $this->post($tutorLogin, ['dni' => $lockedUser->dni, 'password' => 'incorrect'])->assertSessionHasErrors('dni');
        }
        $this->post($coordinatorLogin, ['dni' => $lockedUser->dni, 'password' => 'ExamplePass1234'])->assertSessionHasErrors('dni');
        $this->assertGuest();

        $this->post($tutorLogin, ['dni' => $otherUser->dni, 'password' => 'ExamplePass1234'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($otherUser);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post($tutorLogin, ['dni' => $lockedUser->dni, 'password' => 'ExamplePass1234'])->assertSessionHasErrors('dni');
        $this->assertGuest();
    }

    public function test_idle_session_expires_using_database_parameter(): void
    {
        $user = $this->userWithRoles(RoleName::Tutor);
        $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234']);
        $this->travel(8)->minutes();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_deactivation_invalidates_existing_session_and_keeps_history(): void
    {
        $user = $this->userWithRoles(RoleName::Tutor);
        $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234']);
        $user->update(['is_active' => false, 'session_version' => 2]);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
    }

    public function test_missing_security_configuration_does_not_enable_login(): void
    {
        Setting::where('key', 'security.login_attempts')->delete();
        $user = $this->userWithRoles(RoleName::Tutor);
        $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234'])->assertStatus(503);
        $this->assertGuest();
    }

    public function test_guardian_cannot_manage_users_even_by_direct_post(): void
    {
        $user = $this->userWithRoles(RoleName::Guardian);
        $this->sessionAs($user);
        $this->get('/users')->assertForbidden();
        $this->post('/users', ['name' => 'Intrusión'])->assertForbidden();
    }

    private function userWithRoles(RoleName ...$roles): User
    {
        $user = User::factory()->create(['password' => 'ExamplePass1234']);
        $user->roles()->attach(Role::whereIn('name', array_map(fn ($role) => $role->value, $roles))->pluck('id'));

        return $user;
    }

    private function sessionAs(User $user): void
    {
        $user->refresh();
        if ($user->defaultRole() === RoleName::Guardian) {
            app(ConsentService::class)->accept($user);
        }
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }

    private function sectionAndStudent(string $name): array
    {
        $year = SchoolYear::firstOrCreate(['year' => 2026], ['starts_on' => '2026-03-02', 'ends_on' => '2026-12-18']);
        $level = EducationLevel::firstOrCreate(['name' => 'Secundaria']);
        $grade = Grade::firstOrCreate(['education_level_id' => $level->id, 'name' => 'Primero']);
        $section = Section::create(['school_year_id' => $year->id, 'grade_id' => $grade->id, 'name' => $name]);
        $student = Student::factory()->create();
        Enrollment::create(['student_id' => $student->id, 'school_year_id' => $year->id, 'section_id' => $section->id, 'starts_on' => $year->starts_on]);

        return [$section, $student];
    }
}
