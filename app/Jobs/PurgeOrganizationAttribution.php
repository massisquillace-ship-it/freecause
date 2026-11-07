<?php

namespace App\Jobs;

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
        Petition::where('organization_id', $this->organizationId)
            ->when($this->detachedPetitionId, fn ($q) => $q->orWhere('id', $this->detachedPetitionId))
            ->select('id')->chunkById(50, fn ($petitions) => EdgeCachePurge::urls(EdgeCachePurge::petitionUrls($petitions->pluck('id')->all())));
    }
}
