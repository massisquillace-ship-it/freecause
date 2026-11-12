<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomepageActivities
{
    public function forLocale(string $locale): Collection
    {
        // Bound the scan of the large signatures table; cache IDs, never identities.
        $ids = Cache::remember('homepage:activity-ids:v1:'.$locale, 60, function () use ($locale) {
            $recent = DB::table('signatures')->select('id')->where('created_at', '<=', now())
                ->orderByDesc('created_at')->orderByDesc('id')->limit(2000);

            return $this->publicSignatures($locale)
                ->joinSub($recent, 'recent', 'recent.id', '=', 's.id')
                ->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(10)->pluck('s.id')->all();
        });

        if ($ids === []) {
            return collect();
        }

        // Recheck moderation and current identity on every render, even with warm IDs.
        $rows = $this->publicSignatures($locale)->whereIn('s.id', $ids)
            ->select(['s.id', 's.user_id', 's.name', 's.created_at', 'p.id as petition_id', 'tr.title as petition_title', 'tr.slug as petition_slug'])
            ->orderByDesc('s.created_at')->orderByDesc('s.id')->get();
        $users = User::with('level')->whereIn('id', $rows->pluck('user_id')->filter()->unique())
            ->get(['id', 'level_id', 'verified', 'banned_until', 'name', 'first_name', 'nickname', 'identify_mode'])->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            $user = $users->get($row->user_id);
            $publicUser = $user && $user->verified && ! $user->isBanned();
            $row->profile_user_id = $publicUser ? $user->id : null;
            $row->display_name = $publicUser
                ? ($user->identify_mode === 'nick' ? ($user->nickname ?: __('sig.anonymous')) : $user->display_name)
                : ($row->user_id ? __('sig.anonymous') : ($row->name ?: __('sig.anonymous')));
            if ($row->display_name === __('sig.anonymous')) {
                $row->profile_user_id = null;
            }
            unset($row->name, $row->user_id);

            return $row;
        });
    }

    private function publicSignatures(string $locale)
    {
        return DB::table('signatures as s')
            ->join('petitions as p', 'p.id', '=', 's.petition_id')
            ->join('petition_translations as tr', function ($join) use ($locale) {
                $join->on('tr.petition_id', '=', 'p.id')->where('tr.locale', $locale);
            })
            ->where('s.confirmed', true)->where('s.is_spam', false)->where('s.created_at', '<=', now())
            ->where('p.status', 'published')->where('p.is_active', true)
            ->whereNotNull('tr.title')->where('tr.title', '!=', '')->whereNotNull('tr.slug')->where('tr.slug', '!=', '');
    }
}
