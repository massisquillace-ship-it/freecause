<?php

namespace App\Services;

use App\Jobs\SendOrganizationInvitation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class OrganizationMemberships
{
    public function syncOwner(Organization $org): void
    {
        if ($org->owner_user_id === null) {
            $org->members()->where('role', 'owner')->delete();
            if ($org->status === 'unclaimed') {
                $org->members()->delete();
                $org->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
        } else {
            $org->members()->where('role', 'owner')->where('user_id', '!=', $org->owner_user_id)->delete();
            $org->members()->updateOrCreate(['user_id' => $org->owner_user_id], ['role' => 'owner', 'joined_at' => $org->members()->where('user_id', $org->owner_user_id)->value('joined_at') ?? now()]);
        }
        $owners = $org->members()->where('role', 'owner')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($owners !== ($org->owner_user_id === null ? [] : [(int) $org->owner_user_id])) {
            throw new \LogicException('Organization owner mirror is inconsistent.');
        }
    }

    private function locked(Organization $org): Organization
    {
        return Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
    }

    private function manager(Organization $org, User $actor): void
    {
        abort_unless(app(OrganizationPolicy::class)->manageMembers($actor, $org), 403);
    }

    private function canInvite(Organization $org): bool
    {
        return in_array($org->status, ['pending', 'verified'], true)
            && (! $org->claimed_at || $org->status === 'verified');
    }

    /** Only the hash is stored on the invitation; the returned link is private to this request. */
    public function invite(Organization $organization, User $actor, string $email, string $role, ?int $resendId = null): array
    {
        abort_unless(in_array($role, ['admin', 'editor'], true), 422);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($organization, $actor, $email, $role, $resendId) {
            $org = $this->locked($organization);
            $this->manager($org, $actor);
            abort_unless($this->canInvite($org), 409, __('organization_members.invites_unavailable'));
            if ($org->invitations()->where('created_at', '>=', now()->startOfDay())->count() >= 20) {
                throw ValidationException::withMessages(['email' => __('organization_members.rate_limit')]);
            }
            if ($org->members()->whereHas('user', fn ($q) => $q->where('email', $email))->exists()) {
                throw ValidationException::withMessages(['email' => __('organization_members.already_member')]);
            }
            if ($resendId !== null) {
                $old = $org->invitations()->whereKey($resendId)->lockForUpdate()->firstOrFail();
                abort_if($old->accepted_at || $old->revoked_at, 409);
            }
            // Expiry cannot appear in a deterministic unique index: revoke the old slot explicitly.
            $org->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token = bin2hex(random_bytes(32));
            $invitation = $org->invitations()->create([
                'email' => $email, 'role' => $role, 'token_hash' => hash('sha256', $token),
                'invited_by_user_id' => $actor->id, 'expires_at' => now()->addDays(7),
            ]);
            $link = URL::temporarySignedRoute('organization-invitations.show', $invitation->expires_at, ['locale' => app()->getLocale(), 'token' => $token]);
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'invitation_created', ['invitation_id' => $invitation->id, 'role' => $role]);
            DB::afterCommit(function () use ($invitation, $link) {
                try {
                    SendOrganizationInvitation::dispatch($invitation->id, $link);
                } catch (\Throwable) {
                    Log::warning('Organization invitation delivery deferred', ['invitation_id' => $invitation->id]);
                }
            });

            return [$invitation, $link];
        }, 3);
    }

    public function revoke(Organization $organization, User $actor, int $id): void
    {
        DB::transaction(function () use ($organization, $actor, $id) {
            $org = $this->locked($organization);
            $this->manager($org, $actor);
            $invitation = $org->invitations()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($invitation->accepted_at || $invitation->revoked_at, 409);
            $invitation->update(['revoked_at' => now()]);
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'invitation_revoked', ['invitation_id' => $id]);
        }, 3);
    }

    public function accept(OrganizationInvitation $invitation, User $actor, bool $confirmedMismatch): void
    {
        DB::transaction(function () use ($invitation, $actor, $confirmedMismatch) {
            $org = $this->locked($invitation->organization);
            $invite = $org->invitations()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_if($user->isBanned(), 403);
            abort_unless($invite->usable() && $this->canInvite($org), 410, __('organization_members.invalid_invitation'));
            if (mb_strtolower(trim($user->email)) !== $invite->email && ! $confirmedMismatch) {
                throw ValidationException::withMessages(['confirm_email' => __('organization_members.mismatch_required')]);
            }
            // An existing membership is never promoted or demoted through an invitation.
            $org->members()->firstOrCreate(['user_id' => $user->id], ['role' => $invite->role, 'invited_by_user_id' => $invite->invited_by_user_id, 'joined_at' => now()]);
            $invite->update(['accepted_at' => now()]);
            app(OrganizationLifecycle::class)->record($org, $user->id, 'invitation_accepted', ['invitation_id' => $invite->id, 'member_user_id' => $user->id, 'role' => $org->roleFor($user)]);
        }, 3);
    }

    public function changeRole(Organization $organization, User $actor, int $memberId, string $role): void
    {
        abort_unless(in_array($role, ['admin', 'editor'], true), 422);
        DB::transaction(function () use ($organization, $actor, $memberId, $role) {
            $org = $this->locked($organization);
            $this->manager($org, $actor);
            $member = $org->members()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            abort_if($member->role === 'owner', 403);
            $before = $member->role;
            $member->update(['role' => $role]);
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'member_role_changed', ['member_user_id' => $member->user_id, 'from' => $before, 'to' => $role]);
        }, 3);
    }

    public function remove(Organization $organization, User $actor, int $memberId): void
    {
        DB::transaction(function () use ($organization, $actor, $memberId) {
            $org = $this->locked($organization);
            $this->manager($org, $actor);
            $member = $org->members()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            abort_if($member->role === 'owner', 403);
            $member->delete();
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'member_removed', ['member_user_id' => $member->user_id, 'role' => $member->role]);
        }, 3);
    }

    public function leave(Organization $organization, User $actor): void
    {
        DB::transaction(function () use ($organization, $actor) {
            $org = $this->locked($organization);
            abort_unless(app(OrganizationPolicy::class)->leave($actor, $org), 403, __('organization_members.owner_leave'));
            $member = $org->members()->where('user_id', $actor->id)->firstOrFail();
            $member->delete();
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'member_left', ['member_user_id' => $actor->id, 'role' => $member->role]);
        }, 3);
    }

    public function transfer(Organization $organization, User $actor, int $memberId): void
    {
        DB::transaction(function () use ($organization, $actor, $memberId) {
            $org = $this->locked($organization);
            abort_unless(app(OrganizationPolicy::class)->transferOwnership($actor, $org), 403);
            $member = $org->members()->whereKey($memberId)->where('role', 'admin')->lockForUpdate()->firstOrFail();
            $newOwner = User::whereKey($member->user_id)->lockForUpdate()->firstOrFail();
            abort_if($newOwner->isBanned(), 422);
            $old = $org->owner_user_id;
            $org->members()->where('user_id', $old)->where('role', 'owner')->update(['role' => 'admin']);
            $org->owner_user_id = $newOwner->id;
            $org->save();
            app(OrganizationLifecycle::class)->record($org, $actor->id, 'ownership_transferred', ['old_owner_id' => $old, 'new_owner_id' => $newOwner->id]);
        }, 3);
    }
}
