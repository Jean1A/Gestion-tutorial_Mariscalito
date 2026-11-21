<?php

namespace App\Notifications;

use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Models\CourseAttendanceRecord;
use App\Models\FamilyMessage;
use App\Models\Student;
use App\Notifications\Channels\SchoolWebhookChannel;
use App\Services\ConsentService;
use App\Services\SchoolYearVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class SchoolNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $title, public string $message, public string $url, public array $metadata = [])
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        // El correo se habilita únicamente cuando el titular verificó su dirección.
        $channels = config('school.notification_channels');
        if (config('school-delivery.webhook_enabled') && $notifiable->external_notifications_enabled) {
            $channels[] = SchoolWebhookChannel::class;
        }

        return array_values(array_filter($channels, fn ($channel) => $channel !== 'mail' || ($notifiable->email && $notifiable->hasVerifiedEmail())));
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable->is_active || $notifiable->trashed()) {
            return false;
        }
        foreach (app(SchoolYearVisibility::class)->notificationSources() as $reference => $source) {
            if (isset($this->metadata[$reference]) && ! $source->whereKey($this->metadata[$reference])->exists()) {
                return false;
            }
        }
        if (! isset($this->metadata['student_id'])) {
            return true;
        }
        $student = Student::find($this->metadata['student_id']);
        if (! $student) {
            return false;
        }
        if (($this->metadata['kind'] ?? '') === 'family_message') {
            $message = FamilyMessage::with('tutor.roles', 'guardian.roles')->find($this->metadata['message_id'] ?? 0);
            if (! $message || ! $message->tutor || ! $message->guardian || ! in_array($notifiable->id, [$message->tutor_id, $message->guardian_id], true)) {
                return false;
            }

            return $message->tutor->hasRole(RoleName::Tutor) && $message->guardian->hasRole(RoleName::Guardian)
                && $message->tutor->roles()->where('name', 'tutor')->whereHas('permissions', fn ($q) => $q->where('name', 'communications.send'))->exists()
                && $message->tutor->roles()->where('name', 'tutor')->whereHas('permissions', fn ($q) => $q->where('name', 'students.view'))->exists()
                && $message->guardian->roles()->where('name', 'guardian')->whereHas('permissions', fn ($q) => $q->where('name', 'communications.respond'))->exists()
                && app(ConsentService::class)->hasValidConsent($message->guardian)
                && $message->guardian->students()->whereKey($student->id)->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->exists()
                && $message->tutor->tutoredSections()->where('tutor_assignments.is_active', true)->whereIn('sections.id', $student->enrollments()->where('status', 'active')->select('section_id'))->exists();
        }
        if (($this->metadata['kind'] ?? '') === 'support_report') {
            return $notifiable->hasRole(RoleName::Tutor)
                && $notifiable->roles()->where('name', 'tutor')->whereHas('permissions', fn ($query) => $query->where('name', 'students.view'))->exists()
                && $notifiable->tutoredSections()->whereKey($this->metadata['section_id'])->wherePivot('is_active', true)->exists();
        }
        if (($this->metadata['kind'] ?? '') === 'attendance_alert') {
            return $notifiable->hasRole(RoleName::Tutor) && $notifiable->roles()->where('name', 'tutor')->whereHas('permissions', fn ($q) => $q->where('name', 'students.view'))->exists() && DB::table('tutor_assignments')->where('user_id', $notifiable->id)->where('is_active', true)->whereIn('section_id', $student->enrollments()->where('status', 'active')->select('section_id'))->exists();
        }
        if (! $notifiable->hasRole(RoleName::Guardian) || ! $notifiable->roles()->where('name', 'guardian')->whereHas('permissions', fn ($q) => $q->where('name', 'students.view'))->exists() || ! $notifiable->students()->whereKey($student->id)->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->exists() || ! app(ConsentService::class)->hasValidConsent($notifiable)) {
            return false;
        }
        if (isset($this->metadata['record_id'])) {
            $record = AttendanceRecord::with('session.schoolDay')->find($this->metadata['record_id']);

            return $record && $record->status->value === $this->metadata['status'] && $record->session->schoolDay->is_instructional;
        }
        if (isset($this->metadata['course_record_id'])) {
            $record = CourseAttendanceRecord::with('session.schoolDay')->find($this->metadata['course_record_id']);

            return $record && $record->status->value === $this->metadata['status'] && $record->session->schoolDay->is_instructional;
        }

        return true;
    }

    public function toDatabase(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url] + $this->metadata;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->message)->action('Abrir portal del colegio', $this->url);
    }
}
