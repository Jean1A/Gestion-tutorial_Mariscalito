<?php

namespace App\Jobs;

use App\Enums\RoleName;
use App\Models\AttendanceRecord;
use App\Notifications\SchoolNotification;
use App\Services\ConsentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyAttendance implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $recordId, public string $expectedStatus)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $record = AttendanceRecord::with('enrollment.student.guardians', 'session.schoolDay')->find($this->recordId);
        if (! $record || $record->status->value !== $this->expectedStatus || ! $record->session->schoolDay->is_instructional) {
            return;
        }
        foreach ($record->enrollment->student->guardians as $guardian) {
            if ($guardian->pivot->is_active && $guardian->pivot->verified_at && $guardian->hasRole(RoleName::Guardian) && app(ConsentService::class)->hasValidConsent($guardian)) {
                $guardian->notify(new SchoolNotification('Asistencia registrada', 'Se registró '.$record->status->label().' para '.$record->enrollment->student->full_name.' el '.$record->session->schoolDay->date->format('d/m/Y').'.', route('students.show', $record->enrollment->student_id), ['kind' => 'attendance', 'student_id' => $record->enrollment->student_id, 'record_id' => $record->id, 'status' => $record->status->value]));
            }
        }
    }
}
