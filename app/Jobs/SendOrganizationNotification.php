<?php

namespace App\Jobs;

use App\Mail\OrganizationStatusMail;
use App\Models\Organization;
use App\Models\OrganizationNotification;
use App\Models\User;
use App\Support\AppLog;
use App\Support\Locale;
use App\Support\MailQuota;
use App\Support\Settings;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendOrganizationNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $uniqueFor = 604800;

    public DateTimeInterface $deadline;

    public function __construct(public int $notificationId)
    {
        $this->deadline = now()->addWeek();
    }

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->deadline;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        if (! config('organizations.notifications_enabled') || ! Settings::get('smtp_enabled', false) || MailQuota::tripped()) {
            $this->release(3600);

            return;
        }
        $claimToken = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($claimToken) {
            $notice = OrganizationNotification::whereKey($this->notificationId)->lockForUpdate()->first();
            $claimActive = $notice?->claimed_at
                && Carbon::parse($notice->claimed_at)->gt(now()->subMinutes(10));
            if (! $notice || $notice->sent_at || $notice->cancelled_at
                || $claimActive) {
                return null;
            }
            $org = Organization::find($notice->organization_id);
            $recipient = $notice->recipient_user_id ? User::find($notice->recipient_user_id) : null;
            $claimRejection = $org && $org->status === 'unclaimed' && $notice->event === 'reject'
                && (int) ($notice->payload['rejected_claimant'] ?? 0) === (int) $notice->recipient_user_id;
            $staleClaimRejection = ! empty($notice->payload['rejected_claimant']) && ! $claimRejection;
            if (! $org || $staleClaimRejection || (! $notice->to_admin && (! $recipient || (! $claimRejection && (int) $org->owner_user_id !== (int) $recipient->id) || $recipient->isBanned()))) {
                $notice->forceFill(['cancelled_at' => now()])->save();

                return null;
            }
            $address = $notice->to_admin ? (config('organizations.admin_email') ?: Settings::get('email_to_staff', '', 'global')) : $recipient->email;
            if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                AppLog::error('Organization notification address invalid', 'Notification id: '.$notice->id, 'organizations.notification');
                $this->release(3600);

                return null;
            }
            $locale = $notice->to_admin ? Locale::toShort((string) config('organizations.admin_locale', 'en')) : Locale::toShort($recipient->locale);
            $notice->forceFill(['claimed_at' => now(), 'claim_token' => $claimToken])->save();

            return [$address, $notice->event, $notice->payload, $locale, $notice->to_admin, $org->id];
        });

        if (! $claimed) {
            return;
        }

        try {
            Mail::to($claimed[0])->send(new OrganizationStatusMail($claimed[1], $claimed[2], $claimed[3], $claimed[4], $claimed[5]));
        } catch (\Throwable $e) {
            if (MailQuota::causedBy($e)) {
                MailQuota::trip();
            }
            DB::transaction(fn () => OrganizationNotification::query()
                ->whereKey($this->notificationId)
                ->whereNull('sent_at')
                ->where('claim_token', $claimToken)
                ->update(['claimed_at' => null, 'claim_token' => null]));

            throw $e;
        }

        DB::transaction(fn () => OrganizationNotification::query()
            ->whereKey($this->notificationId)
            ->whereNull('sent_at')
            ->where('claim_token', $claimToken)
            ->update(['sent_at' => now(), 'claimed_at' => null, 'claim_token' => null]));
    }
}
