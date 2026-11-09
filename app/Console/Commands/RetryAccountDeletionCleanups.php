<?php

namespace App\Console\Commands;

use App\Jobs\CleanupDeletedAccount;
use App\Models\AccountDeletionCleanup;
use Illuminate\Console\Command;

class RetryAccountDeletionCleanups extends Command
{
    protected $signature = 'accounts:retry-deletion-cleanups';

    protected $description = 'Requeue pending WordPress cleanup for deleted accounts';

    public function handle(): int
    {
        AccountDeletionCleanup::whereNull('completed_at')
            ->where('created_at', '<=', now()->subMinutes(10))
            ->select('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    CleanupDeletedAccount::dispatch($row->id);
                }
            });

        return self::SUCCESS;
    }
}
