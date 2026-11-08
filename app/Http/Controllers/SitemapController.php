<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PageTranslation;
use App\Support\CacheVersion;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SitemapController extends Controller
{
    private const CACHE_TTL = 3600;

    private const PETITIONS_PER_FILE = 50000;

    private const STATIC_PAGE_SLUGS = [
        'contacts',
    ];

    public function index()
    {
        return $this->xml($this->cached('index.v4', function () {
            return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
                '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n".
                $this->indexEntry('sitemap-pages.xml').
                $this->indexEntry('sitemap-categories.xml').
                $this->indexEntry('sitemap-organizations.xml').
                $this->petitionIndexEntries().
                '</sitemapindex>';
        }));
    }

    private function petitionIndexEntries(): string
    {
        $total = $this->petitionRowCount();

        $out = '';
        foreach (range(1, max(1, (int) ceil($total / $this->petitionsPerFile()))) as $page) {
            $out .= $this->indexEntry('sitemap-petitions-'.$page.'.xml');
        }

        return $out;
    }

    private function petitionsPerFile(): int
    {
        return max(1, (int) config('sitemap.petitions_per_file', self::PETITIONS_PER_FILE));
    }

    private function petitionRowCount(): int
    {
        return $this->cachedInt('petitions.count.v2', function () {
            return DB::table('petitions as p')
                ->join('petition_translations as pt', 'pt.petition_id', '=', 'p.id')
                ->where('p.status', 'published')
                ->where('p.is_active', 1)
                ->whereIn('pt.locale', active_locales())
                ->distinct()
                ->count('p.id');
        });
    }

    public function pages()
    {
        return $this->xml($this->cached('pages.v4', function () {
            $hasPublicOrganizations = Organization::public()->exists();
            $pagesByLocale = [];

            foreach (active_locales() as $locale) {
                $pagesByLocale[$locale] = [$this->pageEntry(canonical_url($locale))];
                $pagesByLocale[$locale][] = $this->pageEntry(canonical_url($locale.'/petitions'));

                foreach (self::STATIC_PAGE_SLUGS as $slug) {
                    $pagesByLocale[$locale][] = $this->pageEntry(canonical_url($locale.'/'.$slug));
                }

                if (in_array($locale, config('organizations.locales'), true)) {
                    $pagesByLocale[$locale][] = $this->pageEntry(canonical_url($locale.'/organizations'));
                    if ($hasPublicOrganizations) {
                        $pagesByLocale[$locale][] = $this->pageEntry(canonical_url($locale.'/organizations/explore'));
                    }
                }
            }

            $entries = $this->withLocaleAlternates($pagesByLocale);

            foreach (PageTranslation::query()
                ->where('published', true)
                ->whereIn('locale', active_locales())
                ->whereNotIn('slug', self::STATIC_PAGE_SLUGS)
                ->orderBy('locale')
                ->get(['locale', 'slug', 'updated_at']) as $tr) {
                $entries[] = $this->pageEntry(
                    canonical_url($tr->locale.'/'.$tr->slug),
                    $tr->updated_at?->toDateString()
                );
            }

            return $this->urlset($entries);
        }));
    }

    public function categories()
    {
        return $this->xml($this->cached(__FUNCTION__, function () {
            $rows = DB::table('categories as c')
                ->join('category_translations as ct', 'ct.category_id', '=', 'c.id')
                ->where('c.is_active', 1)
                ->whereIn('ct.locale', active_locales())
                ->whereExists(fn ($q) => $q->from('petitions as p')
                    ->join('petition_translations as pt', 'pt.petition_id', '=', 'p.id')
                    ->whereColumn('p.category_id', 'c.id')
                    ->whereColumn('pt.locale', 'ct.locale')
                    ->where('p.status', 'published')
                    ->where('p.is_active', 1)
                    ->whereNotNull('pt.title'))
                ->select('c.id', 'ct.locale', 'ct.slug', 'c.updated_at', 'ct.updated_at as tr_updated_at')
                ->get();

            $entries = [];
            foreach ($rows->groupBy('id') as $id => $variants) {
                $alternates = [];
                foreach ($variants as $v) {
                    $alternates[] = [
                        'hreflang' => $v->locale,
                        'href' => canonical_url($v->locale.'/petitions/category-'.$v->slug.'-'.$id),
                    ];
                }

                $dates = $variants->pluck('updated_at')->filter()->map(fn ($d) => (string) $d)->all();
                $lastmod = $dates ? max($dates) : null;
                foreach ($variants as $v) {
                    $entries[] = $this->pageEntry(
                        canonical_url($v->locale.'/petitions/category-'.$v->slug.'-'.$id),
                        $lastmod ? $this->dateOnly($lastmod) : null,
                        $alternates
                    );
                }
            }

            return $this->urlset($entries);
        }));
    }

    public function petitions(int $page = 1)
    {
        if ($page < 1) {
            return $this->xml($this->urlset([]));
        }

        if ($page > max(1, (int) ceil($this->petitionRowCount() / $this->petitionsPerFile()))) {
            return response('', 404, ['Cache-Control' => 'public, max-age=300, s-maxage=300']);
        }

        return $this->xml($this->cached('petitions.v2.'.$page, function () use ($page) {
            $limit = $this->petitionsPerFile();

            $ids = DB::table('petitions as p')
                ->join('petition_translations as pt', 'pt.petition_id', '=', 'p.id')
                ->where('p.status', 'published')
                ->where('p.is_active', 1)
                ->whereIn('pt.locale', active_locales())
                ->distinct()
                ->orderBy('p.id')
                ->offset(max(0, $page - 1) * $limit)
                ->limit($limit)
                ->pluck('p.id');

            $defaultLocale = default_locale();
            $out = $this->urlsetHeader();

            foreach ($ids->chunk(1000) as $chunk) {
                $rows = DB::table('petitions as p')
                    ->join('petition_translations as pt', 'pt.petition_id', '=', 'p.id')
                    ->whereIn('p.id', $chunk)
                    ->whereIn('pt.locale', active_locales())
                    ->select('p.id', 'p.updated_at as p_updated_at', 'pt.locale', 'pt.slug', 'pt.updated_at')
                    ->orderBy('p.id')
                    ->orderBy('pt.locale')
                    ->get();

                foreach ($rows->groupBy('id') as $id => $variants) {
                    $alternates = [];
                    $dates = [];

                    foreach ($variants as $v) {
                        $alternates[] = [
                            'hreflang' => $v->locale,
                            'href' => canonical_url($v->locale.'/petition/'.$v->slug.'/'.$id),
                        ];

                        foreach ([$v->p_updated_at, $v->updated_at] as $date) {
                            if ($date) {
                                $dates[] = (string) $date;
                            }
                        }
                    }

                    $chosen = $variants->firstWhere('locale', $defaultLocale) ?? $variants->first();
                    $loc = canonical_url($chosen->locale.'/petition/'.$chosen->slug.'/'.$id);

                    if ($chosen->locale === $defaultLocale) {
                        $alternates[] = ['hreflang' => 'x-default', 'href' => $loc];
                    }

                    $out .= $this->urlEntry($this->pageEntry($loc, $dates ? $this->dateOnly(max($dates)) : null, $alternates));
                }
            }

            return $out.'</urlset>';
        }));
    }

    public function organizations()
    {
        return $this->xml($this->cached('organizations.v2', function () {
            $out = $this->urlsetHeader();
            $locales = array_intersect(active_locales(), config('organizations.locales'));
            $default = default_locale();
            foreach (Organization::public()->orderBy('id')->cursor() as $org) {
                $alternates = [];
                foreach ($locales as $locale) {
                    $alternates[] = ['hreflang' => $locale, 'href' => canonical_url($locale.'/org/'.rawurlencode($org->slug))];
                }
                $url = canonical_url($default.'/org/'.rawurlencode($org->slug));
                $alternates[] = ['hreflang' => 'x-default', 'href' => $url];
                $out .= $this->urlEntry($this->pageEntry($url, $org->updated_at?->toDateString(), $alternates));
            }

            return $out.'</urlset>';
        }));
    }

    private function withLocaleAlternates(array $pagesByLocale): array
    {
        $entries = [];

        foreach (active_locales() as $locale) {
            $current = $pagesByLocale[$locale];

            foreach ($current as $i => $entry) {
                $alternates = [];
                foreach (active_locales() as $otherLocale) {
                    if (isset($pagesByLocale[$otherLocale][$i])) {
                        $alternates[] = [
                            'hreflang' => $otherLocale,
                            'href' => $pagesByLocale[$otherLocale][$i]['loc'],
                        ];
                    }
                }

                if ($locale === default_locale()) {
                    $alternates[] = ['hreflang' => 'x-default', 'href' => $entry['loc']];
                }

                $entry['alternates'] = $alternates;
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function pageEntry(string $loc, ?string $lastmod = null, array $alternates = []): array
    {
        $entry = ['loc' => $loc];
        if ($lastmod !== null) {
            $entry['lastmod'] = $lastmod;
        }
        if ($alternates !== []) {
            $entry['alternates'] = $alternates;
        }

        return $entry;
    }

    private function indexEntry(string $filename): string
    {
        return "\t<sitemap>\n".
            "\t\t<loc>".$this->xmlEscape(canonical_url($filename))."</loc>\n".
            "\t</sitemap>\n";
    }

    private function urlset(array $entries): string
    {
        $out = $this->urlsetHeader();

        foreach ($entries as $entry) {
            $out .= $this->urlEntry($entry);
        }

        return $out.'</urlset>';
    }

    private function urlsetHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
    }

    private function urlEntry(array $entry): string
    {
        $out = "\t<url>\n";
        $out .= "\t\t<loc>".$this->xmlEscape($entry['loc'])."</loc>\n";

        if (isset($entry['lastmod'])) {
            $out .= "\t\t<lastmod>".$this->xmlEscape($entry['lastmod'])."</lastmod>\n";
        }

        foreach ($entry['alternates'] ?? [] as $alt) {
            $out .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"".$this->xmlEscape($alt['hreflang']).'" href="'.$this->xmlEscape($alt['href'])."\"/>\n";
        }

        return $out."\t</url>\n";
    }

    private function cached(string $key, Closure $callback): string
    {
        return Cache::remember('sitemap:'.CacheVersion::get('sitemap').':'.$key, self::CACHE_TTL, $callback);
    }

    private function cachedInt(string $key, Closure $callback): int
    {
        return (int) Cache::remember('sitemap:'.CacheVersion::get('sitemap').':'.$key, self::CACHE_TTL, $callback);
    }

    private function dateOnly(string $value): string
    {
        return substr($value, 0, 10);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xml(string $content): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300, s-maxage=3600, stale-while-revalidate=3600',
        ]);
    }
}
