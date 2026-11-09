<?php

namespace App\Jobs;

use App\Mail\WelcomeAccountMail;
use App\Models\User;
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

class SendWelcomeEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $uniqueFor = 604800;

    public DateTimeInterface $deadline;

    public function __construct(public int $userId)
    {
        $this->deadline = now()->addWeek();
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
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
        if (! Settings::get('smtp_enabled', false) || MailQuota::tripped()) {
            $this->release(3600);

            return;
        }

        $claimToken = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($claimToken) {
            $user = User::query()->lockForUpdate()->find($this->userId);
            $claimActive = $user?->welcome_email_claimed_at
                && Carbon::parse($user->welcome_email_claimed_at)->gt(now()->subMinutes(10));

            if (! $user || ! $user->welcome_email_pending || $user->welcome_email_sent_at
                || ! $user->verified || ! $user->hasCompleteProfile()
                || $claimActive) {
                return null;
            }

            $user->forceFill([
                'welcome_email_claimed_at' => now(),
                'welcome_email_claim_token' => $claimToken,
            ])->save();

            return [$user->email, Locale::toShort($user->locale)];
        });

        if (! $claimed) {
            return;
        }

        try {
            Mail::to($claimed[0])->send(new WelcomeAccountMail($claimed[1]));
        } catch (\Throwable $e) {
            if (MailQuota::causedBy($e)) {
                MailQuota::trip();
            }
            DB::transaction(fn () => User::query()
                ->whereKey($this->userId)
                ->whereNull('welcome_email_sent_at')
                ->where('welcome_email_claim_token', $claimToken)
                ->update(['welcome_email_claimed_at' => null, 'welcome_email_claim_token' => null]));

            throw $e;
        }

        DB::transaction(fn () => User::query()
            ->whereKey($this->userId)
            ->whereNull('welcome_email_sent_at')
            ->where('welcome_email_claim_token', $claimToken)
            ->update([
                'welcome_email_sent_at' => now(),
                'welcome_email_pending' => false,
                'welcome_email_claimed_at' => null,
                'welcome_email_claim_token' => null,
            ]));
    }
}
