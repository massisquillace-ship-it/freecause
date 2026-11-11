<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\WpSso;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    /**
     * One page of a petition listing: ids and total come from cache, rows are
     * reloaded fresh and put back in the cached order.
     */
    protected function cachedListingPage(Builder $query, string $cacheKey, DateTimeInterface $ttl, int $page, Closure $order): LengthAwarePaginator
    {
        $cached = cache()->remember($cacheKey, $ttl, function () use ($query, $page, $order) {
            $p = $order(clone $query)->paginate(15, ['petitions.id'], 'page', $page);

            return ['ids' => $p->items(), 'total' => $p->total()];
        });

        $ids = collect($cached['ids'] ?? [])->pluck('id')->all();
        $rows = empty($ids) ? collect() : (clone $query)->whereIn('petitions.id', $ids)
            ->with('translations')->get()->sortBy(fn ($r) => array_search($r->id, $ids))->values();

        return (new LengthAwarePaginator($rows, $cached['total'] ?? 0, 15, $page, ['path' => request()->url()]))->withQueryString();
    }

    protected static function intendedDestination(string $fallback = ''): string
    {
        $intended = (string) request()->session()->get('url.intended', '');
        $path = (string) parse_url($intended, PHP_URL_PATH);
        $query = parse_url($intended, PHP_URL_QUERY);

        return self::sanitizeRedirectPath($path.(filled($query) ? '?'.$query : ''), $fallback);
    }

    public static function sanitizeRedirectPath(?string $dest, string $fallback): string
    {
        if ($dest !== null && $dest !== ''
            && str_starts_with($dest, '/')
            && ! str_starts_with($dest, '//')
            && preg_match('#^[A-Za-z0-9/\-._~?&=,%+:!$()*\'@]*$#', $dest) === 1) {
            return $dest;
        }

        return $fallback;
    }

    public static function profileCompletionTarget(string $locale, string $dest): string
    {
        $locale = normalize_locale($locale);
        $profile = "/{$locale}/profile";
        $dest = trim($dest);

        if ($dest === '' || $dest === "/{$locale}" || $dest === $profile) {
            return $profile;
        }

        return $profile.'?'.http_build_query(['return' => $dest]);
    }

    public static function redirectViaSso(User $user, string $locale, string $dest): RedirectResponse
    {
        if (! $user->hasCompleteProfile()) {
            $dest = self::profileCompletionTarget($locale, $dest);
        }

        return redirect()->to(WpSso::loginUrl($user->email, $user->name, $dest));
    }
}
