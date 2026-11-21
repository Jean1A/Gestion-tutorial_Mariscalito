<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Database\Factories\CourseAttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseAttendanceRecord extends Model
{
    /** @use HasFactory<CourseAttendanceRecordFactory> */
    use HasFactory;

    protected $fillable = ['course_attendance_session_id', 'enrollment_id', 'status', 'arrival_time'];

    protected function casts(): array
    {
        return ['status' => AttendanceStatus::class];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CourseAttendanceSession::class, 'course_attendance_session_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function justification(): HasOne
    {
        return $this->hasOne(AbsenceJustification::class);
    }
}
