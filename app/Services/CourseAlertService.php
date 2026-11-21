<?php

namespace App\Services;

use App\Models\CourseAttendanceAlert;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\SchoolNotification;
use Illuminate\Support\Facades\DB;

class CourseAlertService
{
    public function evaluate(): int
    {
        $thresholds = app(SettingsService::class)->required('attendance.percentage_thresholds');
        $consecutive = (int) app(SettingsService::class)->required('attendance.consecutive_days');
        $created = 0;
        CourseAttendanceAlert::where('type', 'percentage')->whereNotIn('threshold', $thresholds)->update(['is_active' => false]);
        CourseAttendanceAlert::where('type', 'consecutive')->where('threshold', '<>', $consecutive)->update(['is_active' => false]);
        CourseOffering::with('course', 'section.schoolYear')->where('is_active', true)->whereHas('section.schoolYear', fn ($q) => $q->where('is_active', true))->chunkById(50, function ($offerings) use ($thresholds, $consecutive, &$created) {
            foreach ($offerings as $offering) {
                foreach (Enrollment::with('student')->where('section_id', $offering->section_id)->where('status', 'active')->whereHas('student', fn ($q) => $q->where('is_active', true))->get() as $enrollment) {
                    $records = app(GuardianPortalService::class)->records($enrollment->student, $offering)->where('enrollment_id', $enrollment->id);
                    $summary = app(GuardianPortalService::class)->summary(clone $records);
                    $ordered = (clone $records)->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->orderByDesc('school_days.date')->select('course_attendance_records.*')->get();
                    $streak = 0;
                    foreach ($ordered as $record) {
                        if ($record->status->value !== 'unjustified_absence') {
                            break;
                        } $streak++;
                    }
                    foreach (array_merge(array_map(fn ($t) => ['percentage', (float) $t, $summary['percentage']], $thresholds), [['consecutive', (float) $consecutive, $streak]]) as [$type,$threshold,$value]) {
                        DB::transaction(function () use ($offering, $enrollment, $type, $threshold, $value, &$created) {
                            Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
                            $alert = CourseAttendanceAlert::firstOrNew(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'type' => $type, 'threshold' => $threshold]);
                            $active = $value >= $threshold;
                            $notify = $active && (! $alert->exists || ! $alert->is_active);
                            if (! $active && ! $alert->exists) {
                                return;
                            }
                            $alert->fill(['observed_value' => $value, 'is_active' => $active])->save();
                            if ($notify) {
                                $created++;
                                app(AuditService::class)->record('course.alert_generated', $alert, ['observed_value' => $value]);
                                $tutors = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'tutor'))->whereIn('id', DB::table('tutor_assignments')->where('section_id', $offering->section_id)->where('is_active', true)->select('user_id'))->get();
                                foreach ($tutors as $tutor) {
                                    $tutor->notify(new SchoolNotification('Alerta · '.$offering->course->name, $enrollment->student->full_name.': '.($type === 'percentage' ? $value.' % de faltas injustificadas' : $value.' sesiones registradas consecutivas sin asistir'), route('students.show', $enrollment->student_id), ['kind' => 'attendance_alert', 'student_id' => $enrollment->student_id, 'course_alert_id' => $alert->id]));
                                }
                            }
                        });
                    }
                }
            }
        });

        return $created;
    }
}
