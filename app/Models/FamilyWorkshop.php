<?php

namespace App\Models;

use Database\Factories\FamilyWorkshopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FamilyWorkshop extends Model
{
    /** @use HasFactory<FamilyWorkshopFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'description', 'is_published', 'is_active'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'is_active' => 'boolean'];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(WorkshopLesson::class)->orderBy('position');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(WorkshopSurvey::class);
    }
}
