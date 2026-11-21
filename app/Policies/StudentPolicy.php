<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\AccessScope;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->permits('students.view');
    }

    public function view(User $user, Student $student): bool
    {
        return app(AccessScope::class)->students($user)->whereKey($student->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->permits('students.manage');
    }

    public function update(User $user, Student $student): bool
    {
        return $user->permits('students.manage') && $this->view($user, $student);
    }

    public function delete(User $user, Student $student): bool
    {
        return false;
    }
}
