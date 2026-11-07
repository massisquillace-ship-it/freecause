<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationLifecycle
{
    public function review(Organization $organization, User $actor, string $action, ?string $reason = null): Organization
    {
        abort_unless(auth('admin')->id() === $actor->id && admin_can('organizations', 'edit'), 403);

        return DB::transaction(function () use ($organization, $actor, $action, $reason) {
            $org = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $old = $org->status;
            $allowed = match ($action) {
                'verify' => in_array($old, ['pending', 'rejected'], true),
                'reject' => $old === 'pending',
                'suspend' => $old === 'verified',
                'restore' => $old === 'suspended',
                default => false,
            };
            abort_unless($allowed && $org->owner_user_id !== null, 409);
            if (in_array($action, ['verify', 'reject', 'suspend', 'restore'], true) && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => __('organizations.reason_required')]);
            }
            $org->status = match ($action) {
                'verify' => 'verified', 'restore' => $org->verified_at ? 'verified' : 'pending', 'reject' => 'rejected', 'suspend' => 'suspended'
            };
            $org->verification_note = $reason;
            if ($action === 'verify' && $org->verified_at === null) {
                $org->verified_at = now();
                $org->verified_by = $actor->id;
            }
            if ($action === 'reject') {
                $org->rejection_reason = $reason;
            }
            if ($action === 'suspend') {
                $org->suspension_reason = $reason;
            }
            if (in_array($action, ['verify', 'restore'], true)) {
                $org->rejection_reason = null;
                $org->suspension_reason = null;
            }
            $org->save();
            $this->record($org, $actor->id, $action, ['from' => $old, 'to' => $org->status, 'review_note' => $reason]);

            return $org;
        }, 3);
    }

    public function reassign(Organization $organization, User $actor, int $newOwnerId): Organization
    {
        abort_unless(auth('admin')->id() === $actor->id && admin_can('organizations', 'edit'), 403);

        return DB::transaction(function () use ($organization, $actor, $newOwnerId) {
            $owner = User::whereKey($newOwnerId)->lockForUpdate()->firstOrFail();
            abort_if($owner->isBanned(), 422);
            $org = Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $old = $org->owner_user_id;
            $org->owner_user_id = $owner->id;
            $org->save();
            $this->record($org, $actor->id, 'owner_reassigned', ['old_owner_id' => $old, 'new_owner_id' => $owner->id]);

            return $org;
        }, 3);
    }

    /** Invoke inside the same transaction as Laravel User deletion; no magazine integration. */
    public function suspendForDeletedOwner(User $owner): void
    {
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        foreach (Organization::where('owner_user_id', $owner->id)->lockForUpdate()->get() as $org) {
            $old = $org->status;
            $org->forceFill(['status' => 'suspended', 'owner_user_id' => null, 'suspension_reason' => 'owner_account_deleted'])->save();
            $this->record($org, null, 'owner_account_deleted', ['old_owner_id' => $owner->id, 'from' => $old, 'to' => 'suspended']);
        }
    }

    public function record(Organization $organization, ?int $actor, string $event, array $details = []): void
    {
        OrganizationEvent::create(['organization_id' => $organization->id, 'actor_user_id' => $actor, 'event' => $event, 'details' => $details, 'created_at' => now()]);
    }
}
