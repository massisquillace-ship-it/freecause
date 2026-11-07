<?php

namespace App\Support;

use App\Models\CategoryTranslation;
use App\Models\PageTranslation;
use App\Models\PetitionTranslation;
use Illuminate\Support\Str;

class Seo
{
    public static function text(?string $value): string
    {
        $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::squish(strip_tags($value));
    }

    public static function isListing(): bool
    {
        return request()->routeIs('petitions.index', 'petitions.byCategory');
    }

    public static function canonical(): string
    {
        $params = [];
        if (self::isListing()) {
            $search = request()->query('q');
            if (is_string($search) && trim($search) !== '') {
                $params['q'] = trim($search);
            }
            $sort = request()->query('sort');
            if (in_array($sort, ['signed', 'trending'], true)) {
                $params['sort'] = $sort;
            }
            $page = request()->query('page');
            if (is_scalar($page) && ctype_digit((string) $page) && (int) $page > 1) {
                $params['page'] = min((int) $page, 500);
            }
        }

        return canonical_url().($params ? '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function description(array $data, string $fallback): string
    {
        $description = '';
        $tr = $data['tr'] ?? null;
        if (request()->routeIs('home')) {
            $description = $data['homeMeta']['meta_description'] ?? __('home.meta_description');
        } elseif (request()->routeIs('petition.show') && $tr instanceof PetitionTranslation) {
            $petition = $data['petition'] ?? null;
            $description = $petition && ! $petition->is_active
                ? $tr->title.'. '.__('pet.offline_title')
                : ($tr->description ?: $tr->title);
        } elseif (request()->routeIs('organizations.show') && isset($data['org'])) {
            $description = $data['org']->localized('tagline') ?: $data['org']->localized('description');
        } elseif ($tr instanceof PageTranslation) {
            $description = $tr->content ?: $tr->title;
        } elseif (self::isListing()) {
            $heading = $data['heading'] ?? $data['pageTitle'] ?? __('list.petitions');
            $description = $heading.'. '.__('home.meta_description');
        }

        return Str::limit(self::text($description ?: ($fallback ?: __('home.meta_description'))), 160, '…');
    }

    public static function robots(array $data): string
    {
        $public = request()->routeIs(
            'home', 'petitions.index', 'petitions.byCategory', 'petition.show', 'organizations', 'organizations.show',
            'page.show', 'faqs', 'how-to-write-a-petition', 'donations',
            'personal-data-processing', 'contacts'
        );
        $petition = $data['petition'] ?? null;
        $filtered = self::isListing() && (
            filled(request()->query('q')) || in_array(request()->query('sort'), ['signed', 'trending'], true)
        );
        $emptyListing = self::isListing() && isset($data['petitions']) && $data['petitions']->isEmpty();
        if (! $public || isset($data['exception']) || $filtered || $emptyListing
            || (request()->routeIs('petition.show') && $petition && $petition->status !== 'published')
            || request()->has('status') || request()->query('view') === 'pages') {
            return 'noindex, follow';
        }

        return 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';
    }

    public static function alternates(array $data): array
    {
        if (str_starts_with(self::robots($data), 'noindex') || (self::isListing() && (int) request()->query('page', 1) > 1)) {
            return [];
        }
        $active = Locale::supported();
        $links = [];
        if (request()->routeIs('petition.show') && isset($data['petition'])) {
            $translations = $data['petition']->translations;
            foreach ($translations->whereIn('locale', $active) as $tr) {
                $links[$tr->locale] = canonical_url($tr->locale.'/petition/'.rawurlencode($tr->slug).'/'.$data['petition']->id);
            }
        } elseif (($data['tr'] ?? null) instanceof PageTranslation) {
            foreach (PageTranslation::where('page_id', $data['tr']->page_id)->where('published', true)->whereIn('locale', $active)->get(['locale', 'slug']) as $tr) {
                $links[$tr->locale] = canonical_url($tr->locale.'/'.rawurlencode($tr->slug));
            }
        } elseif (request()->routeIs('petitions.byCategory') && isset($data['category'])) {
            foreach (CategoryTranslation::where('category_id', $data['category']->id)->whereIn('locale', $active)->whereExists(fn ($q) => $q->from('petitions as p')
                ->join('petition_translations as pt', 'pt.petition_id', '=', 'p.id')
                ->where('p.category_id', $data['category']->id)
                ->whereColumn('pt.locale', 'category_translations.locale')
                ->where('p.status', 'published')
                ->where('p.is_active', 1)
                ->whereNotNull('pt.title'))->get(['locale', 'slug']) as $tr) {
                $links[$tr->locale] = canonical_url($tr->locale.'/petitions/category-'.rawurlencode($tr->slug).'-'.$data['category']->id);
            }
        } elseif (request()->routeIs('home', 'petitions.index', 'contacts')) {
            $suffix = request()->routeIs('petitions.index') ? '/petitions' : (request()->routeIs('contacts') ? '/contacts' : '');
            foreach ($active as $locale) {
                $links[$locale] = canonical_url($locale.$suffix);
            }
        }
        if ($links) {
            $links['x-default'] = $links[Locale::default()] ?? reset($links);
        }

        return $links;
    }
}
