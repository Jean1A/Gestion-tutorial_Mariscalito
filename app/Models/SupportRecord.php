<?php

namespace App\Models;

use Database\Factories\SupportRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportRecord extends Model
{
    /** @use HasFactory<SupportRecordFactory> */
    use HasFactory;

    protected $fillable = ['student_id', 'section_id', 'author_id', 'course_offering_id', 'kind', 'title', 'description', 'agreements', 'visibility', 'severity', 'status', 'occurred_on', 'due_on', 'closure_note', 'closed_at', 'revision', 'dimension', 'family_summary', 'location'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'due_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(SupportFollowUp::class);
    }
}
