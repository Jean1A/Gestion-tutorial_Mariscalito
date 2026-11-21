<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolDay extends Model
{
    protected $fillable = ['school_year_id', 'date', 'is_instructional', 'description'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_instructional' => 'boolean'];
    }
}
