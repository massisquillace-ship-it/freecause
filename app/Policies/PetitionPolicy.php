<?php

namespace App\Policies;

use App\Models\Petition;
use App\Models\User;

class PetitionPolicy
{
    public function update(User $user, Petition $petition): bool
    {
        if (auth('admin')->id() === $user->id && admin_can('petitions', 'edit')) {
            return true;
        }
        if (! $petition->organization_id) {
            return (int) $petition->user_id === (int) $user->id;
        }
        $role = $petition->organization?->roleFor($user);

        return in_array($role, ['owner', 'admin'], true)
            || ($role === 'editor' && (int) $petition->user_id === (int) $user->id);
    }

    public function setOutcome(User $user, Petition $petition): bool
    {
        return $this->update($user, $petition);
    }
}
