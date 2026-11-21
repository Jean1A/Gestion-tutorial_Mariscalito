<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->permits('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->permits('users.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $subject->student_profile_id === null && $user->permits('users.manage') && (
            $user->activeRole() !== RoleName::Coordinator ||
            ($subject->id !== $user->id && $subject->roles()->exists() && ! $subject->roles()->whereNotIn('name', RoleName::delegatedAccountRoles())->exists())
        );
    }

    public function delete(User $user, User $subject): bool
    {
        return false;
    }
}
