<?php

namespace App\Jobs;

use App\Models\AccountDeletionCleanup;
use App\Models\User;
use App\Services\WordPressUserDeletionService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupDeletedAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function __construct(public int $cleanupId)
    {
        $this->afterCommit = true;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addWeek();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(WordPressUserDeletionService $wordPressDeletion): void
    {
        $cleanup = AccountDeletionCleanup::find($this->cleanupId);

        if (! $cleanup || $cleanup->completed_at) {
            return;
        }

        $cleanup->forceFill([
            'attempts' => $cleanup->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $wordPressDeletion->delete(new User(['email' => $cleanup->email]));
        } catch (Throwable $e) {
            $cleanup->forceFill(['last_error' => $e->getMessage()])->save();
            Log::error('WordPress cleanup for deleted account failed', [
                'cleanup_id' => $cleanup->id,
                'user_id' => $cleanup->user_id,
                'attempts' => $cleanup->attempts,
            ]);

            throw $e;
        }

        $cleanup->delete();
    }
}
