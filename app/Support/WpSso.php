<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class WpSso
{
    public static function loginUrl(string $email, string $displayName, string $redirect): string
    {
        $secret = config('app.sso_secret');

        if (! $secret) {
            Log::warning('SSO: sso_secret not configured, skipping WordPress login');

            return $redirect;
        }

        try {
            $payload = base64_encode(json_encode([
                'e' => $email,
                'n' => $displayName,
                'x' => time() + 120,
                'j' => bin2hex(random_bytes(12)),
            ]));

            $sig = hash_hmac('sha256', $payload.'|'.$redirect, $secret);

            return '/magazine/sso.php?'.http_build_query([
                'p' => $payload,
                's' => $sig,
                'r' => $redirect,
            ]);
        } catch (\Exception $e) {
            Log::error('SSO: failed to generate login URL - '.$e->getMessage());

            return $redirect;
        }
    }

    public static function logoutUrl(string $redirect): string
    {
        if (! config('app.sso_secret')) {
            Log::warning('SSO: sso_secret not configured, skipping WordPress logout');

            return $redirect;
        }

        return '/magazine/sso.php?'.http_build_query([
            'action' => 'logout',
            'r' => $redirect,
        ]);
    }
}
