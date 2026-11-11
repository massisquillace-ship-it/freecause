<?php

namespace App\Jobs;

use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Locale;
use App\Support\MailQuota;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendOrganizationInvitation implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $uniqueFor = 604800;

    public DateTimeInterface $deadline;

    public function __construct(public int $invitationId, public string $acceptUrl)
    {
        $this->deadline = now()->addDays(7);
    }

    public function uniqueId(): string
    {
        return (string) $this->invitationId;
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
        $invite = OrganizationInvitation::with('organization')->find($this->invitationId);
        if (! $invite || ! $invite->usable() || $invite->sent_at || ! in_array($invite->organization?->status, ['pending', 'verified'], true)) {
            return;
        }
        if (! config('organizations.notifications_enabled') || MailQuota::paused()) {
            $this->release(3600);

            return;
        }
        $recipient = User::where('email', $invite->email)->first();
        $locale = $recipient ? $recipient->preferredLocale() : Locale::toShort(User::find($invite->invited_by_user_id)?->locale ?? 'en');
        MailQuota::send(fn () => Mail::to($invite->email)->send(new OrganizationInvitationMail($invite->organization->name, $invite->role, $this->acceptUrl, $locale)));
        $invite->update(['sent_at' => now()]);
    }
}
