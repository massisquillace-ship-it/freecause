<?php

namespace App\Http\Middleware;

use App\Support\Ads;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (! $request->is('widget-render/*') && (! $request->is('ads/frame/*') || ! Ads::frameHostMatchesRequest())) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $response->headers->set('Referrer-Policy', $request->routeIs('organizations.badge', 'organizations.consent.revoke', 'organizations.consent.revoke.post') ? 'no-referrer' : 'strict-origin-when-cross-origin');

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->routeIs('organizations.badge')) {
            $csp = "default-src 'none'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'";
        } elseif ($request->is('admin') || $request->is('admin/*')) {
            $csp =
                "default-src 'self'; ".
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' ".
                    'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com '.
                    'https://ajax.googleapis.com https://accounts.google.com '.
                    'https://cdn.ckeditor.com; '.
                "style-src 'self' 'unsafe-inline' ".
                    'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com '.
                    'https://ajax.googleapis.com https://cdn.ckeditor.com; '.
                "img-src 'self' data: blob: https:; ".
                "font-src 'self' https://cdnjs.cloudflare.com https://cdn.ckeditor.com; ".
                "frame-src 'self' https://accounts.google.com; ".
                "connect-src 'self' https://www.googleapis.com https://cdn.jsdelivr.net https://cdn.ckeditor.com; ".
                "object-src 'none'; ".
                "base-uri 'self';";
        } elseif ($request->is('ads/frame/*')) {
            $parentOrigin = Ads::parentOrigin();
            $csp =
                "default-src 'self' https: data: blob:; ".
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; ".
                "style-src 'self' 'unsafe-inline' https:; ".
                "img-src 'self' data: blob: https:; ".
                "font-src 'self' data: https:; ".
                "frame-src 'self' https:; ".
                "connect-src 'self' https:; ".
                "object-src 'none'; ".
                "base-uri 'self'; ".
                'frame-ancestors '.($parentOrigin !== '' ? $parentOrigin : "'self'").';'.
                ($request->isSecure() ? 'upgrade-insecure-requests;' : '');
        } else {
            $frameOrigin = Ads::frameOrigin();

            $inlineHashes = '';

            $inlineHtml = (string) Settings::get('inject_body_html', '')."\n".(string) $request->attributes->get('head_additional_html', '');

            if (preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $inlineHtml, $matches)) {
                foreach ($matches[1] as $body) {
                    $inlineHashes .= " 'sha256-".base64_encode(hash('sha256', $body, true))."'";
                }
            }

            $csp =
                "default-src 'self'; ".
                "script-src 'self' ".
                    'https://cdn.jsdelivr.net/npm/quill@1.3.7/ https://accounts.google.com '.
                    'https://challenges.cloudflare.com '.
                    'https://pagead2.googlesyndication.com https://partner.googleadservices.com '.
                    'https://tpc.googlesyndication.com https://www.googletagservices.com '.
                    'https://googleads.g.doubleclick.net '.
                    'https://www.googletagmanager.com '.
                    'https://www.google-analytics.com https://*.google-analytics.com '.
                    'https://*.analytics.google.com '.
                    'https://ep2.adtrafficquality.google '.
                    $inlineHashes.'; '.
                "style-src 'self' 'unsafe-inline' ".
                    'https://cdn.jsdelivr.net/npm/quill@1.3.7/; '.
                "img-src 'self' data: blob: https:; ".
                "font-src 'self' https://cdnjs.cloudflare.com; ".
                "frame-src 'self' https://accounts.google.com https://www.google.com ".
                    'https://challenges.cloudflare.com '.
                    ($frameOrigin !== '' ? $frameOrigin.' ' : '').
                    'https://googleads.g.doubleclick.net https://tpc.googlesyndication.com '.
                    'https://ep2.adtrafficquality.google '.
                    'https://www.googletagmanager.com https://www.youtube-nocookie.com; '.
                "connect-src 'self' https://www.googleapis.com https://cdn.jsdelivr.net ".
                    'https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net '.
                    'https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com '.
                    'https://*.analytics.google.com https://*.adtrafficquality.google '.
                    'https://ep2.adtrafficquality.google; '.
                "object-src 'none'; ".
                "base-uri 'self';".
                ($request->is('widget-render/*') ? ' frame-ancestors *;' : '').
                ($request->isSecure() ? 'upgrade-insecure-requests;' : '');
        }
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
