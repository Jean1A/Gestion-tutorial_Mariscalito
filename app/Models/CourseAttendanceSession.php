<?php

namespace App\Models;

use Database\Factories\CourseAttendanceSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseAttendanceSession extends Model
{
    /** @use HasFactory<CourseAttendanceSessionFactory> */
    use HasFactory;

    protected $fillable = ['course_offering_id', 'school_day_id', 'recorded_by', 'revision'];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function schoolDay(): BelongsTo
    {
        return $this->belongsTo(SchoolDay::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(CourseAttendanceRecord::class);
    }
}
