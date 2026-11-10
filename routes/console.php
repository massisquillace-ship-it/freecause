<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Jobs\RefreshHomepageMomentum;
use App\Models\Log;
use App\Services\ReviewKeywordRescan;
use App\Support\AppLog;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('signatures:reconcile')->dailyAt('03:00');
Schedule::command('images:cleanup')->dailyAt('05:00');
Schedule::command('auth:clear-resets')->daily();
Schedule::command('backup:run')->dailyAt('02:00')->appendOutputTo(storage_path('logs/backup.log'));
Schedule::command('backup:clean')->dailyAt('04:00')->appendOutputTo(storage_path('logs/backup.log'));
Schedule::command('backup:monitor')->dailyAt('06:00');
Schedule::command('cache:warm')->hourly();
Schedule::job(new RefreshHomepageMomentum)->hourly();
Schedule::command('petition:send-digest', ['--limit' => 100])->dailyAt('09:00');
Schedule::command('emails:petition-new-signatures')->hourly();
Schedule::call(fn () => DB::table('aggregate_traffic')->where('hour', '<', now('UTC')->subDays(90))->delete())
    ->name('aggregate-traffic:prune')->daily();

Schedule::call(fn () => app(DashboardController::class)->warm())->name('dashboard:warm')->withoutOverlapping(30)->everyFiveMinutes();
Schedule::command('organizations:retry-notifications')->everyTenMinutes()->withoutOverlapping();
Schedule::command('accounts:retry-deletion-cleanups')->everyTenMinutes()->withoutOverlapping();

Schedule::command('queue:monitor', [
    '--alert-email' => Settings::get('email_to_staff', '') ?: config('mail.from.address'),
])->everyFiveMinutes();

Schedule::call(function () {
    $deleted = Log::where('created_at', '<', now()->subDays(30))
        ->where(fn ($q) => $q->whereNull('context')->orWhere('context', '!=', 'admin.audit'))
        ->delete();

    if ($deleted > 0) {
        AppLog::info(
            'Old logs pruned',
            "Deleted {$deleted} logs",
            'system.logs'
        );
    }
})->name('logs:prune')->daily();

Schedule::call(function () {
    $finalized = Log::onlyTrashed()->where('deleted_at', '<', now()->subDays(7))->forceDelete();

    if ($finalized > 0) {
        AppLog::info(
            'Soft-deleted logs finalized',
            "Removed {$finalized} logs",
            'system.logs'
        );
    }
})->daily();

Schedule::call(function () {
    // shortcut: petition.review rows are kept forever, move them to their own table if spam_logs grows too large.
    $deleted = DB::table('spam_logs')
        ->where('type', '!=', ReviewKeywordRescan::LOG_TYPE)
        ->where('created_at', '<', now()->subDays(7))
        ->delete();

    if ($deleted > 0) {
        AppLog::info(
            'Old spam logs pruned',
            "Deleted {$deleted} spam logs",
            'system.logs'
        );
    }
})->daily();
