<?php

namespace App\Services;

use App\Models\Petition;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HomepageMagazine
{
    public function posts(string $locale): array
    {
        return cache()->remember('homepage:magazine:v1:'.$locale.':'.CacheVersion::get('home'), 600, function () use ($locale) {
            try {
                $connection = DB::connection('magazine');
                // WordPress's site locale is the default for posts without an explicit locale mapping.
                $siteLocale = (string) $connection->table('options')->where('option_name', 'WPLANG')->value('option_value');
                $siteLocale = strtolower(explode('_', str_replace('-', '_', $siteLocale ?: 'en'))[0]);
                $posts = collect($connection->select("SELECT p.ID, p.post_title, p.post_name, p.post_date,
                    pm_thumb.meta_value AS thumbnail_path,
                    (SELECT related_meta.meta_value FROM abi_postmeta related_meta WHERE related_meta.post_id = p.ID AND related_meta.meta_key = 'related_petition_id' ORDER BY related_meta.meta_id DESC LIMIT 1) AS related_petition_id
                    FROM abi_posts p
                    LEFT JOIN abi_postmeta pm_tid ON pm_tid.post_id = p.ID AND pm_tid.meta_key = '_thumbnail_id'
                    LEFT JOIN abi_postmeta pm_thumb ON pm_thumb.post_id = pm_tid.meta_value AND pm_thumb.meta_key = '_wp_attached_file'
                    WHERE p.post_status = 'publish' AND p.post_type = 'post'
                    AND COALESCE(NULLIF((SELECT locale_meta.meta_value FROM abi_postmeta locale_meta WHERE locale_meta.post_id = p.ID AND locale_meta.meta_key = 'freecause_locale' ORDER BY locale_meta.meta_id DESC LIMIT 1), ''), ?) = ?
                    ORDER BY p.post_date DESC, p.ID DESC LIMIT 3", [$siteLocale, $locale]));
                if ($posts->count() < 3) {
                    return [];
                }
                $related = Petition::visible()->whereIn('id', $posts->pluck('related_petition_id')->filter(fn ($id) => ctype_digit((string) $id)))
                    ->with(['translations' => fn ($q) => $q->where('locale', $locale)])->get()->keyBy('id');
                foreach ($posts as $post) {
                    $post->related_campaign = $related->get($post->related_petition_id)?->translation($locale);
                }

                return $posts->all();
            } catch (\Throwable $exception) {
                Log::warning('Homepage magazine unavailable', ['type' => get_class($exception)]);

                return [];
            }
        });
    }
}
