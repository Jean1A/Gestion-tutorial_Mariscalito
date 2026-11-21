<?php

namespace App\Policies;

use App\Models\StudentImport;
use App\Models\User;

class StudentImportPolicy
{
    public function view(User $user, StudentImport $import): bool
    {
        return $user->permits('students.manage');
    }

    public function downloadAccess(User $user, StudentImport $import): bool
    {
        return $this->view($user, $import) && $user->can('create', User::class) && $user->can('manage-assignments')
            && $import->user_id === $user->id && $import->school_year_id !== null
            && in_array($import->status, ['completed', 'failed'], true)
            && $import->updated_at->greaterThan(now()->subHours(StudentImport::ACCESS_HOURS));
    }
}
