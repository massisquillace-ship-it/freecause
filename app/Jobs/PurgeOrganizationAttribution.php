<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Petition;
use App\Support\EdgeCachePurge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PurgeOrganizationAttribution implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $organizationId, public ?int $detachedPetitionId = null)
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        if (! config('edge-cache.purge_enabled')) {
            return;
        }
        $urls = [canonical_url('sitemap.xml'), canonical_url('sitemap-organizations.xml'), canonical_url('sitemap-pages.xml')];
        $slug = Organization::whereKey($this->organizationId)->value('slug');
        foreach (active_locales() as $locale) {
            $urls[] = canonical_url($locale);
            $urls[] = canonical_url($locale.'/organizations');
            $urls[] = canonical_url($locale.'/organizations/explore');
            if ($slug) {
                $urls[] = canonical_url($locale.'/org/'.rawurlencode($slug));
            }
        }
        EdgeCachePurge::urls($urls);
        Petition::where('organization_id', $this->organizationId)
            ->when($this->detachedPetitionId, fn ($q) => $q->orWhere('id', $this->detachedPetitionId))
            ->select('id')->chunkById(50, fn ($petitions) => EdgeCachePurge::urls(EdgeCachePurge::petitionUrls($petitions->pluck('id')->all())));
    }
}
