<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = ['school_year_id', 'grade_id', 'name', 'is_active'];

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function tutors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tutor_assignments')->withPivot('is_active')->withTimestamps();
    }
}
