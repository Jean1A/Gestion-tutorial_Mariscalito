<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AuditService
{
    public function record(string $action, Model $subject, array $changes = [], ?int $actorId = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes ? json_encode($changes, JSON_THROW_ON_ERROR) : null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
