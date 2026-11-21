<?php

namespace App\Models;

use Database\Factories\MeetingAttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAttendance extends Model
{
    /** @use HasFactory<MeetingAttendanceFactory> */
    use HasFactory;

    protected $fillable = ['family_meeting_id', 'user_id', 'attended', 'recorded_by'];

    protected function casts(): array
    {
        return ['attended' => 'boolean'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(FamilyMeeting::class, 'family_meeting_id');
    }
}
