<?php

namespace App\Services;

use App\Http\Controllers\PetitionController;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\User;
use App\Support\CacheVersion;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomepageData
{
    public function forRequest(Request $request): array
    {
        $locale = app()->getLocale();
        $country = strtoupper((string) $request->attributes->get('country', ''));
        $country = Countries::isValid($country) ? $country : null;
        $key = 'homepage:v1:'.CacheVersion::get('home').':'.CacheVersion::get('sitemap').':'.$locale.':'.($country ?? 'world');

        $data = Cache::remember($key, 600, fn () => $this->load($locale, $country));
        // Identity choices and account deletion must take effect even while campaign data is cached.
        $petitions = $data['campaigns']->concat($data['victories']);
        $users = User::whereIn('id', $petitions->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'first_name', 'last_name', 'nickname', 'identify_mode'])->keyBy('id');
        foreach ($petitions as $petition) {
            $petition->setRelation('user', $users->get($petition->user_id));
        }
        // One shared threshold also controls navigation and promotion; public_launch is unrelated.
        $data['directoryReady'] = $data['organizations_count'] >= max(1, (int) config('organizations.min_directory_count', 20));
        $data['countryName'] = $country && class_exists(\Locale::class)
            ? \Locale::getDisplayRegion('und_'.$country, $locale) : ($country ?? __('home.hero.europe'));

        return $data;
    }

    private function petitions(string $locale)
    {
        return PetitionController::listingQuery($locale)
            ->addSelect(['petitions.user_id', 'petitions.organization_id', 'petitions.country', 'petitions.created_at', 'petitions.signatures_7d_cache', 'petitions.outcome', 'petitions.outcome_at', 'petitions.outcome_summary'])
            ->with(['organization.causes']);
    }

    private function load(string $locale, ?string $country): array
    {
        $stats = Organization::public()->selectRaw("COUNT(*) AS organizations_count, SUM(CASE WHEN status = 'unclaimed' THEN 1 ELSE 0 END) AS unclaimed_count, SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) AS verified_count")->first();
        $totalSignatures = Cache::remember('homepage:total-signatures', 3600, fn () => (int) Petition::visible()->sum('signature_count'));

        $orgQuery = Organization::public()->select('organizations.*')->withCampaignTotals()->with(['causes', 'mainCampaign.translations']);
        $organizations = (clone $orgQuery)->where('signatures_7d_cache', '>=', 20)
            ->when($country, fn ($q) => $q->where('country', $country))
            ->orderByDesc('signatures_7d_cache')->orderByDesc('signatures_total')->orderBy('id')->limit(6)->get();
        $organizationsTrending = $organizations->count() >= 3;
        if (! $organizationsTrending) {
            $fallback = clone $orgQuery;
            if ($country) {
                $fallback->orderByRaw('CASE WHEN country = ? THEN 0 ELSE 1 END', [$country]);
            }
            $organizations = $fallback->orderByDesc('signatures_total')->orderBy('id')->limit(6)->get();
        }
        if ($organizations->count() < 3) {
            $organizations = collect();
        }

        $campaignQuery = $this->petitions($locale)->where('signature_count', '>=', 50);
        if ($country) {
            $campaignQuery->orderByRaw('CASE WHEN petitions.country = ? THEN 0 ELSE 1 END', [$country]);
        }
        $campaigns = (clone $campaignQuery)->where('signatures_7d_cache', '>', 0)
            ->orderByDesc('signatures_7d_cache')->orderByDesc('petitions.id')->limit(6)->get();
        $campaignsTrending = $campaigns->count() >= 3;
        if (! $campaignsTrending) {
            $campaigns = (clone $campaignQuery)->where('petitions.created_at', '>=', now()->subYear())
                ->orderByDesc('signature_count')->orderByDesc('petitions.id')->limit(6)->get();
        }
        if ($campaigns->count() < 3) {
            $campaigns = collect();
        }

        $victories = $this->petitions($locale)->where('outcome', 'victory')->whereNotNull('outcome_at')
            ->orderByDesc('outcome_at')->orderByDesc('petitions.id')->limit(3)->get();
        $causeCounts = DB::table('cause_organization')->joinSub(Organization::public()->select('id'), 'public_orgs', 'public_orgs.id', '=', 'cause_organization.organization_id')
            ->selectRaw('cause_id, COUNT(*) AS organization_count')->groupBy('cause_id');
        $causes = Cause::query()->joinSub($causeCounts, 'counts', 'counts.cause_id', '=', 'causes.id')
            ->select('causes.*', 'counts.organization_count')->orderBy('causes.id')->limit(20)->get();

        return [
            'organizations' => $organizations, 'organizationsTrending' => $organizationsTrending,
            'campaigns' => $campaigns, 'campaignsTrending' => $campaignsTrending, 'victories' => $victories, 'causes' => $causes,
            'organizations_count' => (int) $stats->organizations_count, 'unclaimed_count' => (int) $stats->unclaimed_count,
            'verified_count' => (int) $stats->verified_count, 'total_signatures' => $totalSignatures,
        ];
    }
}
