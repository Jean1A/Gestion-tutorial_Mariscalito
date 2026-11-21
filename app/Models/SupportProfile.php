<?php

namespace App\Models;

use Database\Factories\SupportProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportProfile extends Model
{
    /** @use HasFactory<SupportProfileFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'school_year_id', 'family_context', 'health_context', 'interests', 'support_needs', 'updated_by', 'revision'];

    protected function casts(): array
    {
        return [];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
