<?php

namespace App\Models;

use Database\Factories\StaffStudentMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffStudentMessage extends Model
{
    /** @use HasFactory<StaffStudentMessageFactory> */
    use HasFactory;

    protected $fillable = ['course_offering_id', 'enrollment_id', 'tutor_id', 'teacher_id', 'sender_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
