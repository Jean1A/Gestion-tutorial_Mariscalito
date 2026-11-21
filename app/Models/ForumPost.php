<?php

namespace App\Models;

use Database\Factories\ForumPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumPost extends Model
{
    /** @use HasFactory<ForumPostFactory> */
    use HasFactory;

    protected $fillable = ['family_workshop_id', 'user_id', 'body', 'status', 'moderated_by', 'moderated_at'];

    protected function casts(): array
    {
        return ['moderated_at' => 'datetime'];
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(FamilyWorkshop::class, 'family_workshop_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
