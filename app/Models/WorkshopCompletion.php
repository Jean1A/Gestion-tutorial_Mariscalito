<?php

namespace App\Models;

use Database\Factories\WorkshopCompletionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkshopCompletion extends Model
{
    /** @use HasFactory<WorkshopCompletionFactory> */
    use HasFactory;

    protected $fillable = ['workshop_lesson_id', 'user_id'];
}
