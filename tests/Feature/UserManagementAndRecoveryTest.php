<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\QueuedPasswordReset;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class UserManagementAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RoleSeeder::class);
        foreach (['security.login_attempts' => 5, 'security.lock_minutes' => 2, 'security.idle_minutes' => 7] as $key => $value) {
            Setting::create(compact('key', 'value'));
        }
    }

    public function test_administrator_creates_edits_deactivates_and_reactivates_without_deletion(): void
    {
        $administrator = $this->user(RoleName::Administrator);
        $this->login($administrator);
        $tutorRole = Role::where('name', 'tutor')->value('id');
        $data = ['name' => 'Tutor de prueba', 'dni' => '99000001', 'email' => 'teacher@example.test', 'phone' => '999999999', 'roles' => [$tutorRole], 'password' => 'TemporaryPass1234', 'password_confirmation' => 'TemporaryPass1234'];
        $this->post('/users', $data)->assertRedirect('/users')->assertSessionHasNoErrors();
        $subject = User::where('dni', '99000001')->firstOrFail();
        $this->assertTrue(Hash::check('TemporaryPass1234', $subject->password));
        $this->assertTrue($subject->must_change_password);
        $data['name'] = 'Tutor actualizado';
        unset($data['password'], $data['password_confirmation']);
        $this->put('/users/'.$subject->id, $data)->assertRedirect('/users')->assertSessionHasNoErrors();
        $this->patch('/users/'.$subject->id.'/status', ['is_active' => false])->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['id' => $subject->id, 'is_active' => false, 'deleted_at' => null]);
        $this->patch('/users/'.$subject->id.'/status', ['is_active' => true])->assertRedirect('/users');
        $this->assertDatabaseHas('users', ['id' => $subject->id, 'is_active' => true]);
        $audit = DB::table('audit_logs')->where('subject_id', $subject->id)->pluck('changes')->join(' ');
        $this->assertStringNotContainsString('TemporaryPass1234', $audit);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $subject->id, 'action' => 'user.deactivated', 'user_id' => $administrator->id]);
    }

    public function test_last_administrator_cannot_remove_own_administrator_role(): void
    {
        $administrator = $this->user(RoleName::Administrator);
        $this->login($administrator);
        $this->put('/users/'.$administrator->id, ['name' => $administrator->name, 'dni' => $administrator->dni, 'email' => $administrator->email, 'roles' => [Role::where('name', 'tutor')->value('id')]])->assertSessionHasErrors('roles');
        $this->assertTrue($administrator->fresh()->hasRole(RoleName::Administrator));
    }

    public function test_email_change_requires_verification_again(): void
    {
        $administrator = $this->user(RoleName::Administrator);
        $subject = $this->user(RoleName::Guardian);
        $this->login($administrator);
        $this->put('/users/'.$subject->id, ['name' => $subject->name, 'dni' => $subject->dni, 'email' => 'new@example.test', 'roles' => $subject->roles()->pluck('roles.id')->all()])->assertSessionHasNoErrors();
        $this->assertNull($subject->fresh()->email_verified_at);
    }

    public function test_recovery_needs_verified_email_and_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $verified = $this->user(RoleName::Guardian);
        $unverified = $this->user(RoleName::Guardian);
        $unverified->forceFill(['email_verified_at' => null])->save();
        $this->post('/forgot-password', ['email' => $verified->email])->assertSessionHas('status');
        Notification::assertSentTo($verified, QueuedPasswordReset::class, fn ($notification) => $notification instanceof ShouldQueue);
        $this->post('/forgot-password', ['email' => $unverified->email])->assertSessionHas('status');
        Notification::assertNotSentTo($unverified, QueuedPasswordReset::class);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertSessionHas('status');
    }

    public function test_valid_token_changes_password_invalidates_sessions_and_cannot_be_reused(): void
    {
        $user = $this->user(RoleName::Guardian);
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'RecoveredPass1234', 'password_confirmation' => 'RecoveredPass1234'];
        $this->post('/reset-password', $data)->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('RecoveredPass1234', $user->fresh()->password));
        $this->assertSame(2, $user->fresh()->session_version);
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_admin_password_recovery_requires_identity_confirmation(): void
    {
        $administrator = $this->user(RoleName::Administrator);
        $subject = $this->user(RoleName::Guardian);
        $this->login($administrator);
        $data = ['name' => $subject->name, 'dni' => $subject->dni, 'email' => $subject->email, 'roles' => $subject->roles()->pluck('roles.id')->all(), 'password' => 'RecoveredPass1234', 'password_confirmation' => 'RecoveredPass1234'];
        $this->put('/users/'.$subject->id, $data)->assertSessionHasErrors('identity_verified');
        $this->put('/users/'.$subject->id, $data + ['identity_verified' => '1'])->assertRedirect('/users')->assertSessionHasNoErrors();
        $this->assertTrue($subject->fresh()->must_change_password);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create(['password' => 'ExamplePass1234']);
        $user->roles()->attach(Role::where('name', $role->value)->value('id'));

        return $user->refresh();
    }

    private function login(User $user): void
    {
        $this->post('/login', ['dni' => $user->dni, 'password' => 'ExamplePass1234'])->assertRedirect('/dashboard');
    }
}
