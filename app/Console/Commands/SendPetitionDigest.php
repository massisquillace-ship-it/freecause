<?php

namespace App\Console\Commands;

use App\Mail\PetitionDigestMail;
use App\Models\Petition;
use App\Models\User;
use App\Support\AppLog;
use App\Support\Locale;
use App\Support\MailQuota;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendPetitionDigest extends Command
{
    protected $signature = 'petition:send-digest {--dry-run : Count recipients without sending} {--limit= : Maximum number of users to process}';

    protected $description = 'Send the petition digest to verified users who opted in to product emails';

    public function handle(): int
    {
        $lock = Cache::lock('petition-digest', 21600);
        if (! $lock->get()) {
            $this->info('Petition digest: already running');

            return self::SUCCESS;
        }

        try {
            return $this->runDigest();
        } finally {
            $lock->release();
        }
    }

    private function runDigest(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $defaultLocale = default_locale();

        if (! $dryRun && MailQuota::productPaused('petition_digest_enabled')) {
            if (MailQuota::tripped()) {
                AppLog::error('Petition digest aborted', 'Mail provider quota exhausted, stopped dispatching.', 'emails.digest');

                $this->warn('Mail quota exhausted - digest aborted.');
            } else {
                $this->warn('Product mail paused - petition digest skipped.');
            }

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $petitionsByLocale = [];
        $useCursor = $limit > 0 && ! $dryRun;
        $cursor = $useCursor ? (int) Cache::get('digest:cursor', 0) : 0;
        $lastId = $cursor;
        $limited = false;
        $aborted = false;

        User::query()
            ->whereNotNull('email')
            ->where('verified', true)
            ->where('notify_product', true)
            ->whereNull('unsubscribed_at')
            ->where('id', '>', $cursor)
            ->chunkById(200, function ($users) use (
                $dryRun,
                $limit,
                $defaultLocale,
                &$sent,
                &$skipped,
                &$failed,
                &$processed,
                &$petitionsByLocale,
                &$lastId,
                &$limited,
                &$aborted
            ) {
                if (MailQuota::tripped()) {
                    AppLog::error('Petition digest aborted', 'Mail provider quota exhausted, stopped dispatching.', 'emails.digest');
                    $this->warn('Mail quota exhausted - digest aborted.');
                    $aborted = true;

                    return false;
                }

                foreach ($users as $user) {
                    if ($limit > 0 && $processed >= $limit) {
                        $limited = true;

                        return false;
                    }

                    $lastId = $user->id;
                    $processed++;
                    $dedupeKey = null;

                    try {
                        $locale = Locale::toShort($user->locale ?: $defaultLocale);

                        if (! array_key_exists($locale, $petitionsByLocale)) {
                            $petitionsByLocale[$locale] = $this->recentPetitions($locale, $defaultLocale);
                        }

                        $petitions = $petitionsByLocale[$locale];

                        if ($petitions->isEmpty()) {
                            $skipped++;

                            continue;
                        }

                        if (! $dryRun) {
                            if (! Cache::add($dedupeKey = 'digest:'.$user->id.':'.today()->toDateString(), 1, now()->endOfDay())) {
                                $skipped++;

                                continue;
                            }

                            Mail::to($user->email)->queue((new PetitionDigestMail($user, $petitions))->onQueue('bulk'));
                        }

                        $sent++;
                    } catch (\Throwable $e) {
                        if ($dedupeKey !== null) {
                            Cache::forget($dedupeKey);
                        }

                        $failed++;
                        AppLog::error('Petition digest failed', 'User ID: '.$user->id.' | '.$e->getMessage(), 'emails.digest');
                    }
                }

                return true;
            });

        if ($useCursor) {
            $limited || $aborted ? Cache::forever('digest:cursor', $lastId) : Cache::forget('digest:cursor');
        }

        $summary = sprintf(
            'processed=%d sent=%d skipped=%d failed=%d dry_run=%s',
            $processed,
            $sent,
            $skipped,
            $failed,
            $dryRun ? 'yes' : 'no'
        );

        AppLog::info('Petition digest run', $summary);
        $this->info('Petition digest: '.$summary);

        return self::SUCCESS;
    }

    private function recentPetitions(string $locale, string $defaultLocale): Collection
    {
        return Petition::query()
            ->with('translations')
            ->select([
                'petitions.id',
                'petitions.signature_count',
                'petitions.created_at',
            ])
            ->translated($locale, 'pt_locale', 'pt_default', ['title' => 'tr_title', 'slug' => 'tr_slug'], $defaultLocale)
            ->where(function ($query) {
                $query->whereNotNull('pt_locale.title')
                    ->orWhereNotNull('pt_default.title');
            })
            ->where('petitions.status', 'published')
            ->where('petitions.is_active', 1)
            ->where('petitions.created_at', '>=', now()->subDay())
            ->orderByDesc('petitions.created_at')
            ->limit(5)
            ->get();
    }
}
