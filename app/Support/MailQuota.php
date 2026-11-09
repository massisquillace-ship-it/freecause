<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class MailQuota
{
    private const KEY = 'mail:quota-exhausted';

    public static function tripped(): bool
    {
        return (bool) Cache::get(self::KEY);
    }

    public static function paused(): bool
    {
        return ! Settings::get('smtp_enabled', false) || self::tripped();
    }

    public static function productPaused(string $key): bool
    {
        return self::paused() || ! Settings::get($key, true);
    }

    public static function trip(): void
    {
        Cache::put(self::KEY, 1, now()->endOfDay());
    }

    public static function causedBy(\Throwable $e): bool
    {
        return preg_match('/email allowance/i', $e->getMessage()) === 1;
    }
}
