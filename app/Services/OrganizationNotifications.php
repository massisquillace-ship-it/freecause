<?php

namespace App\Services;

use App\Jobs\SendOrganizationNotification;
use App\Models\Organization;
use App\Models\OrganizationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrganizationNotifications
{
    public static function record(Organization $org, string $event, array $details = []): void
    {
        $targets = match ($event) {
            'applied' => [false, true], 'resubmitted' => [true],
            'verify', 'reject', 'suspend', 'restore', 'donation' => [false],
            default => [],
        };
        foreach ($targets as $admin) {
            $notice = OrganizationNotification::create([
                'organization_id' => $org->id, 'recipient_user_id' => $admin ? null : ($details['rejected_claimant'] ?? $org->owner_user_id),
                'event' => $event, 'to_admin' => $admin,
                'payload' => ['name' => $org->name, 'slug' => $org->slug, 'reason' => $details['review_note'] ?? '', 'restored_verified' => $org->status === 'verified', 'rejected_claimant' => $details['rejected_claimant'] ?? null],
            ]);
            DB::afterCommit(function () use ($notice) {
                if (! config('organizations.notifications_enabled')) {
                    return;
                }
                try {
                    SendOrganizationNotification::dispatch($notice->id);
                } catch (\Throwable) {
                    // The committed outbox row remains pending; the scheduled recovery retries it.
                    Log::warning('Organization notification dispatch deferred', ['notification_id' => $notice->id]);
                }
            });
        }
    }
}
