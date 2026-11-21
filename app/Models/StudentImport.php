<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentImport extends Model
{
    public const ACCESS_HOURS = 24;

    protected $fillable = ['user_id', 'section_id', 'school_year_id', 'education_level_id', 'path', 'format', 'status', 'imported_count', 'error_count', 'failure_message'];

    protected $hidden = ['path'];
}
