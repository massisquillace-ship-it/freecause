<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class Spam
{
    public static function turnstileEnabled(): bool
    {
        return (bool) Settings::get('turnstile_enabled', false, 'global')
            && trim((string) Settings::get('turnstile_site_key', '', 'global')) !== ''
            && trim((string) Settings::get('turnstile_secret', '', 'global')) !== '';
    }

    public static function verifyTurnstile(?string $token): bool
    {
        if (! self::turnstileEnabled()) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => (string) Settings::get('turnstile_secret', '', 'global'),
                    'response' => $token,
                    'remoteip' => request()->ip(),
                ]);

            return $response->successful() && ($response->json('success') === true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static array $badKeywords = [
        'viagra',
        'casino',
        'bitcoin',
        'porn',
        'escort',
        'seo service',
        'cheap pills',
        'free money',
        'paypal',
    ];

    /**
     * @param  array<int, string>  $ignore
     */
    public static function isSpam(string $text, array $ignore = [], bool $ignoreDerived = false): bool
    {
        $text = strtr(strtolower($text), ['1' => 'i']);

        $ignored = array_map(fn ($term) => mb_strtolower(trim((string) $term)), $ignore);

        foreach (self::$badKeywords as $word) {
            if (in_array($word, $ignored, true) && ($ignoreDerived || ! self::hasDerivedOccurrence($word, $text))) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($word, '/').'/u', $text) === 1) {
                return true;
            }
        }

        if (substr_count($text, 'http') >= 2) {
            return true;
        }

        return false;
    }

    protected static function hasDerivedOccurrence(string $word, string $text): bool
    {
        if (! str_contains($text, $word)) {
            return false;
        }

        $pattern = '/\b'.preg_quote($word, '/').'\b/u';

        if (preg_match($pattern, $text) !== 1) {
            return true;
        }

        $stripped = preg_replace($pattern, ' ', $text);

        return $stripped === null || str_contains($stripped, $word);
    }

    /**
     * @return array<int, string>
     */
    public static function reviewOwned(): array
    {
        return array_values(array_intersect(self::$badKeywords, ReviewKeywords::TERMS));
    }

    public static function log(string $type, string $payload): void
    {
        $limit = $type === 'petition.review' ? 60000 : 8000;

        DB::table('spam_logs')->insert([
            'type' => $type,
            'ip' => request()->ip(),
            'payload' => mb_strcut(mb_scrub($payload, 'UTF-8'), 0, $limit, 'UTF-8'),
            'created_at' => now(),
        ]);
    }
}
