<?php

namespace App\Support;

use App\Http\Middleware\CanonicalHost;

// Resolves Adsterra ad config, preferring runtime admin Settings (group "ads")
// over the config/ads.php baseline. Admin edits the enable flag, host and the
// per-slot zone keys without a deploy; structural bits (dimensions, format,
// network path) stay in config since they are not client-tunable.
class Ads
{
    // settings group. Reuses "global" so the existing admin Global Options
    // save loop (which writes group "global") persists these without changes.
    private const GROUP = 'global';

    // master switch: Settings override, else config default.
    public static function enabled(): bool
    {
        if (OrganizationPrivateFlow::sensitive()) {
            return false;
        }

        $v = Settings::get('ads_enabled', null, self::GROUP);

        if ($v === null) {
            return (bool) config('ads.enabled', false);
        }

        return (bool) $v;
    }

    // network script host, trailing slash trimmed.
    public static function host(): string
    {
        $v = Settings::get('ads_host', null, self::GROUP);
        $host = $v !== null && trim((string) $v) !== ''
            ? (string) $v
            : (string) config('ads.host', '');

        return rtrim($host, '/');
    }

    // dedicated frame origin, trailing slash trimmed. Anything that is not an
    // exact scheme://host origin (path, query, whitespace, userinfo) falls back
    // to the config default; if that fails too the result is '' and consumers
    // skip the ad instead of emitting a broken origin into the CSP or an iframe.
    public static function frameOrigin(): string
    {
        $v = Settings::get('ads_frame_origin', null, self::GROUP);
        $origin = $v !== null && trim((string) $v) !== ''
            ? self::bareOrigin((string) $v)
            : '';

        if ($origin === '' || self::isSiteHost($origin)) {
            $origin = self::bareOrigin((string) config('ads.frame_origin', ''));
        }

        if (self::isSiteHost($origin)) {
            return '';
        }

        return $origin;
    }

    private static function isSiteHost(string $origin): bool
    {
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        $parentHost = strtolower((string) parse_url(self::parentOrigin(), PHP_URL_HOST));

        return $host === $parentHost || in_array($host, CanonicalHost::PUBLIC_HOSTS, true);
    }

    // exact scheme://host origin, or '' for anything else.
    private static function bareOrigin(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $parts = parse_url($url);

        if (empty($parts['host']) || array_diff_key($parts, ['scheme' => true, 'host' => true, 'port' => true]) !== []) {
            return '';
        }

        return $url;
    }

    // canonical parent origin (scheme://host[:port]) for frame-ancestors and the
    // frame's postMessage target; '' when neither configured URL has a host.
    public static function parentOrigin(): string
    {
        foreach ([config('app.canonical_url'), config('app.url')] as $candidate) {
            $url = (array) parse_url((string) $candidate);

            if (! empty($url['host'])) {
                return ($url['scheme'] ?? 'https').'://'.$url['host']
                    .(! empty($url['port']) ? ':'.$url['port'] : '');
            }
        }

        return '';
    }

    public static function frameUrl(string $name): string
    {
        // Avoid reusing a permanent redirect cached before the dedicated ad host was configured.
        $v = substr(md5(self::host().'|'.(self::slot($name)['key'] ?? '')), 0, 8);

        return self::frameOrigin().'/ads/frame/'.$name.'?v='.$v;
    }

    public static function frameHost(): string
    {
        return strtolower((string) parse_url(self::frameOrigin(), PHP_URL_HOST));
    }

    public static function frameHostMatchesRequest(): bool
    {
        return self::frameHost() !== '' && self::frameHost() === strtolower(request()->getHost());
    }

    // resolved slot config, or null for an unknown slot. The zone key is
    // overridden by Settings (ads_slot_{name}_key); dims/format/path from config.
    public static function slot(string $name): ?array
    {
        $cfg = config("ads.slots.$name");

        if (! is_array($cfg)) {
            return null;
        }

        $override = Settings::get("ads_slot_{$name}_key", null, self::GROUP);
        if ($override !== null && trim((string) $override) !== '') {
            $cfg['key'] = (string) $override;
        }

        return $cfg;
    }

    public static function canShow(string $name): bool
    {
        $cfg = self::slot($name);

        return self::enabled() && $cfg !== null && ! empty($cfg['key']) && self::frameOrigin() !== '';
    }

    // True the first time a slot name is rendered for the current request, false
    // after. The layout emits a default ad stack on every page; this keeps pages
    // that already place a slot inline from emitting the same zone twice, which
    // Adsterra treats as an error (duplicate code corrupts stats and CPM).
    public static function renderOnce(string $name): bool
    {
        $key = 'ads_slot_rendered_'.$name;

        if (request()->attributes->has($key)) {
            return false;
        }

        request()->attributes->set($key, true);

        return true;
    }

    public static function pick(string $placement): ?string
    {
        $pool = (array) config("ads.placements.$placement", []);
        $pool = array_values(array_filter($pool, fn ($n) => self::canShow($n) && ! request()->attributes->has('ads_slot_rendered_'.$n)));
        if ($pool === []) {
            return null;
        }
        $key = 'ads_pick_'.$placement;
        $i = (int) (request()->attributes->get($key, 0));
        request()->attributes->set($key, $i + 1);

        return $pool[$i % count($pool)];
    }
}
