<?php

namespace App\Models;

use Database\Factories\WorkshopSurveyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkshopSurvey extends Model
{
    /** @use HasFactory<WorkshopSurveyFactory> */
    use HasFactory;

    protected $fillable = ['family_workshop_id', 'user_id', 'rating', 'comment'];
}
