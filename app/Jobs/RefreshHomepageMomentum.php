<?php

namespace App\Jobs;

use App\Models\Petition;
use App\Support\CacheVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshHomepageMomentum implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public int $timeout = 300;

    public int $tries = 3;

    public function handle(): void
    {
        // The date range uses existing signature indexes. Public requests only read counters.
        $counts = DB::table('signatures')->where('confirmed', true)->where('is_spam', false)
            ->where('created_at', '>=', now()->subDays(7))->where('created_at', '<=', now())
            ->selectRaw('petition_id AS id, COUNT(*) AS total')->groupBy('petition_id')->get();

        DB::transaction(function () use ($counts) {
            DB::table('petitions')->where('signatures_7d_cache', '!=', 0)->update(['signatures_7d_cache' => 0]);
            $this->writeCounters('petitions', $counts);
            $organizations = Petition::visible()->whereNotNull('organization_id')
                ->selectRaw('organization_id AS id, SUM(signatures_7d_cache) AS total')->groupBy('organization_id')->get();
            DB::table('organizations')->where('signatures_7d_cache', '!=', 0)->update(['signatures_7d_cache' => 0]);
            $this->writeCounters('organizations', $organizations);
        });

        Cache::put('homepage:total-signatures', (int) Petition::visible()->sum('signature_count'), now()->addHours(2));
        CacheVersion::bump('home');
    }

    private function writeCounters(string $table, $counts): void
    {
        foreach ($counts->chunk(300) as $chunk) {
            $case = 'CASE id';
            $bindings = [];
            foreach ($chunk as $row) {
                $case .= ' WHEN ? THEN ?';
                array_push($bindings, (int) $row->id, min(4294967295, (int) $row->total));
            }
            $case .= ' ELSE 0 END';
            $ids = $chunk->pluck('id')->map(fn ($id) => (int) $id)->all();
            DB::update('UPDATE '.$table.' SET signatures_7d_cache = '.$case.' WHERE id IN ('.implode(',', $ids).')', $bindings);
        }
    }
}
