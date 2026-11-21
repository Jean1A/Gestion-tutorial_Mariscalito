<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationRecipient extends Model
{
    protected $fillable = ['communication_id', 'user_id', 'student_id', 'read_at', 'response', 'response_note', 'responded_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
