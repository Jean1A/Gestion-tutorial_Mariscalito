<?php

namespace App\Services;

use App\Models\AbsenceJustification;
use App\Models\AcademicPeriod;
use App\Models\AttendanceAlert;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\FamilyMeeting;
use App\Models\MeetingAttendance;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\StaffStudentMessage;
use App\Models\StudentImport;
use App\Models\SupportFollowUp;
use App\Models\SupportProfile;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use Illuminate\Database\Eloquent\Builder;

class SchoolYearVisibility
{
    public const SCOPE = 'available-school-year';

    /** @return array<string, Builder> */
    public function notificationSources(): array
    {
        return [
            'school_year_id' => SchoolYear::available(),
            'record_id' => AttendanceRecord::query(),
            'course_record_id' => CourseAttendanceRecord::query(),
            'alert_id' => AttendanceAlert::query(),
            'course_alert_id' => CourseAttendanceAlert::query(),
            'recipient_id' => CommunicationRecipient::query(),
            'section_id' => Section::query(),
        ];
    }

    public function register(): void
    {
        foreach ([AcademicPeriod::class, Communication::class, Enrollment::class, SchoolDay::class, Section::class, SupportProfile::class] as $model) {
            $model::addGlobalScope(self::SCOPE, function (Builder $query): void {
                $query->whereIn($query->qualifyColumn('school_year_id'), SchoolYear::available()->select('id'));
            });
        }
        foreach ([
            AbsenceJustification::class => 'record',
            AttendanceAlert::class => 'enrollment',
            AttendanceRecord::class => 'enrollment',
            AttendanceSession::class => 'section',
            CommunicationRecipient::class => 'communication',
            CourseAttendanceAlert::class => 'enrollment',
            CourseAttendanceRecord::class => 'enrollment',
            CourseAttendanceSession::class => 'schoolDay',
            CourseOffering::class => 'section',
            CourseResult::class => 'enrollment',
            MeetingAttendance::class => 'meeting',
            StaffStudentMessage::class => 'enrollment',
            SupportFollowUp::class => 'record',
            SupportRecord::class => 'section',
            TutoringSession::class => 'section',
        ] as $model => $relation) {
            $model::addGlobalScope(self::SCOPE, fn (Builder $query) => $query->whereHas($relation));
        }
        StudentImport::addGlobalScope(self::SCOPE, fn (Builder $query) => $query->where(fn (Builder $imports) => $imports
            ->whereIn('school_year_id', SchoolYear::available()->select('id'))
            ->orWhereIn('section_id', Section::query()->select('id'))));
        FamilyMeeting::addGlobalScope(self::SCOPE, fn (Builder $query) => $query
            ->where(fn (Builder $meetings) => $meetings->whereNull('section_id')->orWhereHas('section')));
    }
}
