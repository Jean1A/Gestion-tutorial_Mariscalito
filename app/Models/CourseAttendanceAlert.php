<?php

namespace App\Models;

use Database\Factories\CourseAttendanceAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAttendanceAlert extends Model
{
    /** @use HasFactory<CourseAttendanceAlertFactory> */
    use HasFactory;

    protected $fillable = ['course_offering_id', 'enrollment_id', 'type', 'threshold', 'observed_value', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
