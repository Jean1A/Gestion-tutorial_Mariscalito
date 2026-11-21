<?php

namespace App\Models;

use Database\Factories\AbsenceJustificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceJustification extends Model
{
    /** @use HasFactory<AbsenceJustificationFactory> */
    use HasFactory;

    protected $fillable = ['course_attendance_record_id', 'user_id', 'reason', 'description', 'path', 'mime', 'status', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CourseAttendanceRecord::class, 'course_attendance_record_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
