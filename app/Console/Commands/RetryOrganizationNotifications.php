<?php

namespace App\Console\Commands;

use App\Jobs\SendOrganizationNotification;
use App\Models\OrganizationNotification;
use Illuminate\Console\Command;

class RetryOrganizationNotifications extends Command
{
    protected $signature = 'organizations:retry-notifications';

    protected $description = 'Requeue pending organization transactional notifications';

    public function handle(): int
    {
        if (! config('organizations.notifications_enabled')) {
            return self::SUCCESS;
        }
        OrganizationNotification::whereNull('sent_at')->whereNull('cancelled_at')->where('created_at', '<=', now()->subMinutes(10))
            ->select('id')->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    SendOrganizationNotification::dispatch($row->id);
                }
            });

        return self::SUCCESS;
    }
}
