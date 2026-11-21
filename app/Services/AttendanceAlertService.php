<?php

namespace App\Services;

use App\Models\AttendanceAlert;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\SchoolNotification;
use Illuminate\Support\Facades\DB;

class AttendanceAlertService
{
    public function evaluate(): int
    {
        $settings = app(SettingsService::class);
        $thresholds = $settings->required('attendance.percentage_thresholds');
        $consecutive = (int) $settings->required('attendance.consecutive_days');
        AttendanceAlert::where('type', 'percentage')->whereNotIn('threshold', $thresholds)->update(['is_active' => false]);
        AttendanceAlert::where('type', 'consecutive')->where('threshold', '<>', $consecutive)->update(['is_active' => false]);
        $created = 0;
        Enrollment::with('student')->where('status', 'active')->whereHas('student', fn ($q) => $q->where('is_active', true))->whereHas('section.schoolYear', fn ($q) => $q->where('is_active', true))->chunkById(100, function ($enrollments) use ($thresholds, $consecutive, &$created) {
            foreach ($enrollments as $enrollment) {
                $summary = app(AttendanceSummaryService::class);
                $percentage = $summary->percentage($summary->base()->whereHas('enrollment', fn ($q) => $q->where('student_id', $enrollment->student_id)->where('school_year_id', $enrollment->school_year_id)))['percentage'];
                $streak = $summary->consecutive($enrollment);
                foreach (array_merge(array_map(fn ($threshold) => ['percentage', (float) $threshold, $percentage], $thresholds), [['consecutive', (float) $consecutive, (float) $streak]]) as [$type,$threshold,$observed]) {
                    DB::transaction(function () use ($enrollment, $type, $threshold, $observed, &$created) {
                        // Un bloqueo por matrícula serializa evaluaciones superpuestas del scheduler.
                        Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
                        $alert = AttendanceAlert::firstOrNew(['enrollment_id' => $enrollment->id, 'type' => $type, 'threshold' => $threshold]);
                        $active = $observed >= $threshold;
                        $notify = $active && (! $alert->exists || ! $alert->is_active);
                        if (! $active && ! $alert->exists) {
                            return;
                        }
                        $alert->fill(['observed_value' => $observed, 'is_active' => $active])->save();
                        if ($notify) {
                            $created++;
                            app(AuditService::class)->record('attendance.alert_generated', $alert, ['observed_value' => $observed]);
                            foreach (User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'tutor'))->whereIn('id', DB::table('tutor_assignments')->where('section_id', $enrollment->section_id)->where('is_active', true)->select('user_id'))->get() as $tutor) {
                                $tutor->notify(new SchoolNotification('Alerta de inasistencia', $enrollment->student->full_name.' alcanzó '.($type === 'percentage' ? $observed.' % de faltas injustificadas.' : $observed.' días lectivos consecutivos de falta injustificada.'), route('students.show', $enrollment->student_id), ['kind' => 'attendance_alert', 'student_id' => $enrollment->student_id, 'alert_id' => $alert->id]));
                            }
                        }
                    });
                }
            }
        });

        return $created;
    }
}
