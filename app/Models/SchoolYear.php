<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SchoolYear extends Model
{
    protected $fillable = ['year', 'starts_on', 'ends_on', 'is_active'];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('closed_at'));
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean', 'closed_at' => 'datetime'];
    }
}
