<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\CommunicationRecipient;
use App\Models\User;
use App\Services\AccessScope;

class CommunicationRecipientPolicy
{
    public function view(User $user, CommunicationRecipient $recipient): bool
    {
        return $user->activeRole() === RoleName::Guardian && $user->permits('communications.respond') && $recipient->user_id === $user->id && app(AccessScope::class)->students($user)->whereKey($recipient->student_id)->exists();
    }

    public function respond(User $user, CommunicationRecipient $recipient): bool
    {
        return $this->view($user, $recipient);
    }
}
