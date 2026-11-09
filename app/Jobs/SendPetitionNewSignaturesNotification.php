<?php

namespace App\Jobs;

use App\Mail\PetitionNewSignaturesMail;
use App\Models\PetitionSignatureNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPetitionNewSignaturesNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $notification = PetitionSignatureNotification::with('petition.user')
                ->whereKey($this->notificationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($notification->status === 'delivered') {
                return;
            }

            $owner = $notification->petition->user;
            if (! $owner) {
                return;
            }

            Mail::to($owner->email)->send(new PetitionNewSignaturesMail(
                $notification->petition,
                $owner,
                $notification->total,
                collect($notification->signers),
            ));

            $updated = PetitionSignatureNotification::query()
                ->whereKey($notification->id)
                ->where('status', '!=', 'delivered')
                ->update(['status' => 'delivered', 'updated_at' => now()]);

            if ($updated) {
                DB::table('petitions')
                    ->where('id', $notification->petition_id)
                    ->where(function ($query) use ($notification) {
                        $query->whereNull('last_notified_at')
                            ->orWhere('last_notified_at', '<', $notification->cutoff_at);
                    })
                    ->update(['last_notified_at' => $notification->cutoff_at]);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        PetitionSignatureNotification::query()
            ->whereKey($this->notificationId)
            ->where('status', '!=', 'delivered')
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
