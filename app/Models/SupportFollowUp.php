<?php

namespace App\Models;

use Database\Factories\SupportFollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportFollowUp extends Model
{
    /** @use HasFactory<SupportFollowUpFactory> */
    use HasFactory;

    protected $fillable = ['support_record_id', 'user_id', 'note', 'next_on'];

    protected function casts(): array
    {
        return ['next_on' => 'date'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(SupportRecord::class, 'support_record_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
