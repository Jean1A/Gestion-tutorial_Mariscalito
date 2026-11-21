<?php

namespace App\Models;

use Database\Factories\TutoringSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TutoringSession extends Model
{
    /** @use HasFactory<TutoringSessionFactory> */
    use HasFactory;

    protected $fillable = ['section_id', 'user_id', 'topic', 'scheduled_on', 'objectives', 'outcomes', 'attendees', 'evidence_path', 'evidence_mime', 'status', 'revision', 'dimensions', 'materials'];

    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'attendees' => 'array', 'dimensions' => 'array', 'materials' => 'array'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $hidden = ['evidence_path', 'materials'];
}
