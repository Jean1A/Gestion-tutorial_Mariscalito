<?php

namespace App\Models;

use App\Services\SchoolYearVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['dni', 'first_name', 'last_name', 'birth_date', 'is_active'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'is_active' => 'boolean'];
    }

    public function scopeVisibleInSchoolYears(Builder $query): Builder
    {
        return $query->where(fn (Builder $students) => $students->whereHas('enrollments')
            ->orWhereDoesntHave('enrollments', fn (Builder $enrollments) => $enrollments->withoutGlobalScope(SchoolYearVisibility::SCOPE)));
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'guardian_student')->withPivot(['relationship', 'is_active', 'verified_by', 'verified_at'])->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return $this->last_name.', '.$this->first_name;
    }
}
