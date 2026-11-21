<?php

namespace Tests\Feature;

use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Channels\SchoolWebhookChannel;
use App\Notifications\SchoolNotification;
use App\Services\ConsentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolDeliveryAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        Queue::fake();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['phone' => '999999999']);
        $u->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $u->refresh()->unsetRelation('roles');
    }

    private function family(): array
    {
        $c = CourseOffering::factory()->create();
        $t = $this->user('tutor');
        $t->tutoredSections()->attach($c->section_id, ['is_active' => true]);
        $s = Student::factory()->create();
        Enrollment::create(['student_id' => $s->id, 'school_year_id' => $c->section->school_year_id, 'section_id' => $c->section_id, 'starts_on' => '2026-03-02']);
        $g = $this->user('guardian');
        $g->students()->attach($s, ['relationship' => 'Familiar', 'is_active' => true, 'verified_by' => $t->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($g);

        return [$t, $g, $s, $c];
    }

    private function login(User $u): void
    {
        $this->actingAs($u)->withSession(['active_role' => $u->defaultRole()->value, 'session_version' => $u->session_version, 'last_activity_at' => now()->timestamp]);
    }

    public function test_external_channel_requires_opt_in_and_revalidates_links_without_sending_private_content(): void
    {
        [$t,$g,$s] = $this->family();
        config(['school-delivery.webhook_enabled' => true, 'school-delivery.webhook_url' => 'https://provider.example.test/delivery', 'school-delivery.webhook_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);
        $n = new SchoolNotification('Asistencia', 'Información privada no debe salir', 'http://localhost/portal', ['kind' => 'communication', 'student_id' => $s->id]);
        $n->id = 'test-delivery-id';
        $channel = new SchoolWebhookChannel;
        $channel->send($g, $n);
        Http::assertNothingSent();
        $g->forceFill(['external_notifications_enabled' => true])->save();
        $channel->send($g, $n);
        Http::assertSent(fn ($r) => $r['to'] === '+51999999999' && ! str_contains($r->body(), 'Información privada') && $r->hasHeader('Idempotency-Key', 'test-delivery-id:'.$g->id.':whatsapp'));
        Http::fake();
        $g->students()->updateExistingPivot($s->id, ['is_active' => false]);
        $channel->send($g, $n);
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_not_silently_marked_as_success(): void
    {
        [$t,$g,$s] = $this->family();
        $g->forceFill(['external_notifications_enabled' => true])->save();
        config(['school-delivery.webhook_enabled' => true, 'school-delivery.webhook_url' => 'https://provider.example.test/delivery', 'school-delivery.webhook_token' => 'test-token']);
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(RequestException::class);
        (new SchoolWebhookChannel)->send($g, new SchoolNotification('Aviso', 'Texto', 'http://localhost', ['student_id' => $s->id]));
    }

    public function test_guardian_can_opt_out_and_staff_cannot_change_this_preference(): void
    {
        [$t,$g] = $this->family();
        $this->login($g);
        $this->post(route('privacy.external-notifications'), ['enabled' => 1])->assertRedirect();
        $this->assertTrue($g->fresh()->external_notifications_enabled);
        $this->post(route('privacy.external-notifications'), ['enabled' => 0])->assertRedirect();
        $this->assertFalse($g->fresh()->external_notifications_enabled);
        $this->login($t);
        $this->post(route('privacy.external-notifications'), ['enabled' => 1])->assertForbidden();
    }

    public function test_reschedule_preserves_old_responses_resets_confirmation_and_rejects_stale_revision(): void
    {
        [$t,$g,$s,$c] = $this->family();
        $comm = Communication::create(['user_id' => $t->id, 'school_year_id' => $c->section->school_year_id, 'type' => 'appointment', 'title' => 'Citación', 'body' => 'Reunión de coordinación', 'scheduled_at' => now()->addDay(), 'location' => 'Sala A']);
        $recipient = CommunicationRecipient::create(['communication_id' => $comm->id, 'user_id' => $g->id, 'student_id' => $s->id, 'response' => 'reschedule_requested', 'response_note' => 'Familia solicita otra fecha.', 'responded_at' => now(), 'read_at' => now()]);
        $this->login($t);
        $data = ['scheduled_at' => '2026-11-03T10:00', 'location' => 'Sala B', 'reason' => 'Nueva fecha acordada con la familia.', 'revision' => 1];
        $this->post(route('communications.reschedule', $comm), $data)->assertRedirect();
        $this->assertSame('pending', $recipient->fresh()->response);
        $this->assertNull($recipient->fresh()->read_at);
        $this->assertSame(2, $comm->fresh()->revision);
        $history = DB::table('communication_schedule_changes')->first();
        $this->assertStringContainsString('reschedule_requested', $history->previous_responses);
        $this->get(route('communications.receipts', $comm))->assertOk()->assertSee('Nueva fecha acordada');
        $this->post(route('communications.reschedule', $comm), $data)->assertSessionHasErrors('revision');
        $this->login($g);
        $this->post(route('communications.reschedule', $comm), array_replace($data, ['revision' => 2]))->assertForbidden();
    }

    public function test_backup_commands_reject_unsupported_connections_and_unsafe_restore_names(): void
    {
        config(['school.backup_binary' => null, 'school-delivery.restore_binary' => null]);
        $this->artisan('school:backup')->assertFailed();
        $this->artisan('school:restore-test', ['backup' => '../school.sql'])->assertFailed();
        $this->artisan('school:readiness')->assertSuccessful();
    }

    public function test_restore_rejects_server_global_settings_before_connecting(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('backups/unsafe.sql', 'SET @@GLOBAL.GTID_PURGED="unsafe";');
        $connection = config('database.default');
        try {
            config(['database.default' => 'mysql', 'school-delivery.restore_binary' => PHP_BINARY]);
            $this->artisan('school:restore-test', ['backup' => 'unsafe.sql'])->expectsOutput('La copia no puede contener instrucciones que cambien la base de destino.')->assertFailed();
        } finally {
            config(['database.default' => $connection]);
        }
    }
}
