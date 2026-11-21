<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuardianConsent extends Model
{
    protected $fillable = ['user_id', 'policy_version', 'policy_hash', 'student_ids', 'accepted_at', 'withdrawn_at', 'ip_address'];

    protected function casts(): array
    {
        return ['student_ids' => 'array', 'accepted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }
}
