<?php

namespace App\Models;

use Database\Factories\CourseResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseResult extends Model
{
    /** @use HasFactory<CourseResultFactory> */
    use HasFactory;

    protected $fillable = ['course_offering_id', 'enrollment_id', 'academic_period_id', 'achievement', 'is_published', 'recorded_by'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }
}
