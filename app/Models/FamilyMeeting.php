<?php

namespace App\Models;

use Database\Factories\FamilyMeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyMeeting extends Model
{
    /** @use HasFactory<FamilyMeetingFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'section_id', 'title', 'scheduled_at', 'location', 'is_published', 'is_active'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'is_published' => 'boolean', 'is_active' => 'boolean'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }
}
