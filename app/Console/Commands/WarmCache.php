<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\PetitionController;
use App\Models\Category;
use App\Models\Language;
use App\Services\HomepageData;
use App\Services\HomepageMagazine;
use App\Services\PageContentService;
use App\Support\CacheKeys;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WarmCache extends Command
{
    protected $signature = 'cache:warm {--locale= : Specific locale to warm}';

    protected $description = 'Warm up application caches for faster first-page loads';

    public function handle(): int
    {
        $startTime = microtime(true);

        $locales = $this->option('locale')
            ? [normalize_locale($this->option('locale'))]
            : Language::where('is_active', 1)->pluck('code')->toArray();

        $this->info('Warming cache for locales: '.implode(', ', $locales));

        foreach ($locales as $locale) {
            $this->warmLocaleCache($locale);
        }

        $this->warmSharedCache();

        $elapsed = round((microtime(true) - $startTime) * 1000);
        $this->info("Cache warming complete in {$elapsed}ms.");

        return 0;
    }

    private function warmLocaleCache(string $locale): void
    {
        $this->line("  Warming {$locale}...");

        $this->warmHomeContent($locale);
        Category::cachedActive($locale);
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale($locale);
            app(HomepageData::class)->forRequest(Request::create('/'.$locale));
            app(HomepageMagazine::class)->posts($locale);
        } finally {
            app()->setLocale($previousLocale);
        }
        $this->warmPetitionsCache($locale);
    }

    private function warmHomeContent(string $locale): void
    {
        app(PageContentService::class)->getPage('home', $locale);
    }

    private function warmPetitionsCache(string $locale): void
    {
        for ($page = 1; $page <= 10; $page++) {
            Cache::remember(CacheKeys::petitionsIndex($locale, $page), 300, function () use ($locale, $page) {
                $petitions = PetitionController::listingQuery($locale)
                    ->orderByDesc('petitions.id')
                    ->paginate(15, ['petitions.id'], 'page', $page);

                return ['ids' => $petitions->items(), 'total' => $petitions->total()];
            });
        }
    }

    private function warmSharedCache(): void
    {
        $this->line('  Warming shared caches...');

        Cache::remember('active_languages', 300, function () {
            return Language::where('is_active', 1)->pluck('code')->toArray();
        });

        Cache::remember('default_language', 300, function () {
            return Language::where('is_default', 1)->value('code') ?? 'en';
        });

        Cache::remember('active_languages_full', 60, function () {
            return Language::where('is_active', 1)
                ->orderByDesc('is_default')
                ->get();
        });

        try {
            app(DashboardController::class)->warm();
        } catch (\Throwable) {
        }
    }
}
