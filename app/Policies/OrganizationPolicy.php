<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    private function platform(User $user): bool
    {
        return auth('admin')->id() === $user->id && admin_can('organizations', 'edit');
    }

    public function view(User $user, Organization $org): bool
    {
        return $this->platform($user) || $org->roleFor($user) !== null;
    }

    public function update(User $user, Organization $org): bool
    {
        return $this->platform($user) || ($org->status !== 'suspended' && in_array($org->roleFor($user), ['owner', 'admin'], true));
    }

    public function manageMembers(User $user, Organization $org): bool
    {
        return $this->platform($user) || in_array($org->roleFor($user), ['owner', 'admin'], true);
    }

    public function transferOwnership(User $user, Organization $org): bool
    {
        return $this->platform($user) || $org->roleFor($user) === 'owner';
    }

    public function resubmit(User $user, Organization $org): bool
    {
        return $this->transferOwnership($user, $org);
    }

    public function requestDeletion(User $user, Organization $org): bool
    {
        return $this->transferOwnership($user, $org);
    }

    public function publish(User $user, Organization $org): bool
    {
        return $org->status === 'verified' && $org->owner_user_id !== null && $this->view($user, $org);
    }

    public function leave(User $user, Organization $org): bool
    {
        return in_array($org->roleFor($user), ['admin', 'editor'], true);
    }
}
