<?php

namespace App\Models;

use Database\Factories\WorkshopLessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopLesson extends Model
{
    /** @use HasFactory<WorkshopLessonFactory> */
    use HasFactory;

    protected $fillable = ['family_workshop_id', 'title', 'content', 'position'];

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(FamilyWorkshop::class, 'family_workshop_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(WorkshopCompletion::class);
    }
}
