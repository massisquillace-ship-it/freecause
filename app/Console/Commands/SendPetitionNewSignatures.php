<?php

namespace App\Console\Commands;

use App\Jobs\SendPetitionNewSignaturesNotification;
use App\Models\Petition;
use App\Models\PetitionSignatureNotification;
use App\Support\AppLog;
use App\Support\MailQuota;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendPetitionNewSignatures extends Command
{
    protected $signature = 'emails:petition-new-signatures {--dry-run : Count petitions without sending}';

    protected $description = 'Send hourly batched new-signature notifications to petition owners';

    public function handle(): int
    {
        $lock = Cache::lock('new-sigs', 600);
        if (! $lock->get()) {
            $this->info('New signatures: already running');

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
        $cutoff = now();
        $fallback = (clone $cutoff)->subHour();

        if (! $dryRun && MailQuota::productPaused('petition_new_signatures_enabled')) {
            $this->warn('Product mail paused - new signature notifications skipped.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $counted = 0;

        // Candidate petitions: any signature in the lookback window, read via
        // signatures_confirmed_created_index. 25h covers the 1h fallback plus a
        // day of missed hourly runs. Older unnotified signatures are picked up
        // (and counted exactly below) once the petition gets a new signature.
        $candidateIds = DB::table('signatures')
            ->where('confirmed', 1)
            ->where('is_spam', 0)
            ->where('created_at', '>', (clone $cutoff)->subHours(25))
            ->where('created_at', '<=', $cutoff)
            ->distinct()
            ->pluck('petition_id');

        // Exact count since each petition's last notification, per chunk of candidates.
        $totals = collect();
        foreach ($candidateIds->chunk(1000) as $chunk) {
            $totals = $totals->union(DB::table('signatures')
                ->join('petitions', 'petitions.id', '=', 'signatures.petition_id')
                ->whereIn('petitions.id', $chunk->values()->all())
                ->whereNotNull('petitions.user_id')
                ->where('signatures.confirmed', 1)
                ->where('signatures.is_spam', 0)
                ->whereRaw('signatures.created_at > COALESCE(petitions.last_notified_at, ?)', [$fallback])
                ->where('signatures.created_at', '<=', $cutoff)
                ->select('signatures.petition_id as petition_id')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('signatures.petition_id')
                ->pluck('total', 'petition_id'));
        }

        foreach ($totals->keys()->sort()->chunk(1000) as $chunk) {
            foreach (Petition::with('user')->whereIn('id', $chunk->values()->all())->orderBy('id')->get() as $petition) {
                $petitionId = $petition->id;
                $total = (int) ($totals[$petitionId] ?? 0);

                if ($total === 0) {
                    continue;
                }

                $counted++;
                $owner = $petition->user;

                if (! $owner || ! $owner->wantsNewSignatureEmails()) {
                    $skipped++;

                    continue;
                }

                $since = $petition->last_notified_at ?? $fallback;
                $signers = DB::table('signatures')
                    ->where('petition_id', $petitionId)
                    ->where('confirmed', 1)
                    ->where('is_spam', 0)
                    ->where('created_at', '>', $since)
                    ->where('created_at', '<=', $cutoff)
                    ->whereNotNull('user_id')
                    ->orderByDesc('id')
                    ->limit(3)
                    ->select(['name', 'text'])
                    ->get()
                    ->map(fn ($row) => [
                        'name' => $row->name,
                        'snippet' => Str::limit((string) $row->text, 140),
                    ]);

                if (! $dryRun) {
                    $notification = PetitionSignatureNotification::query()
                        ->where('petition_id', $petitionId)
                        ->whereIn('status', ['pending', 'failed'])
                        ->latest('id')
                        ->first();

                    if ($notification?->status === 'pending' && $notification->updated_at->gt(now()->subHours(3))) {
                        $skipped++;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($petitionId, $cutoff, $total, $signers, $notification) {
                            $notification ??= PetitionSignatureNotification::create([
                                'petition_id' => $petitionId,
                                'cutoff_at' => $cutoff,
                                'total' => (int) $total,
                                'signers' => $signers->all(),
                            ]);

                            $notification->touch();

                            SendPetitionNewSignaturesNotification::dispatch($notification->id)->afterCommit();
                        });
                    } catch (\Throwable $e) {
                        AppLog::error('New-signatures email failed', 'Petition ID: '.$petitionId.' | '.$e->getMessage(), 'emails.new-signatures');
                        $skipped++;

                        continue;
                    }
                }
                $sent++;
            }
        }

        $summary = sprintf('petitions=%d sent=%d skipped=%d dry_run=%s', $counted, $sent, $skipped, $dryRun ? 'yes' : 'no');
        AppLog::info('New-signatures digest run', $summary);
        $this->info('New signatures: '.$summary);

        return self::SUCCESS;
    }
}
