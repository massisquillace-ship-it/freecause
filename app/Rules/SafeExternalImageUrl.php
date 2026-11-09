<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;

class SafeExternalImageUrl implements ValidationRule
{
    private int $maxBytes;

    private array $allowedTypes = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private int $connectTimeout = 4;

    private int $totalTimeout = 6;

    private int $maxRedirects = 3;

    public function __construct(int $maxBytes = 4 * 1024 * 1024)
    {
        $this->maxBytes = $maxBytes;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = trim((string) $value);
        if ($url === '') {
            return;
        }

        $error = $this->shapeChecks($url);
        if ($error !== null) {
            $fail($error);

            return;
        }

        if (! config('petition.verify_external_image', true)) {
            return;
        }

        $parts = parse_url($url);
        if ($this->resolvePublicIps($parts['host']) === null) {
            $fail('The image link points to a private or local address.');

            return;
        }

        $result = Cache::remember('imgchk:'.sha1($url), 86400, fn () => $this->inspect($url));

        $messages = [
            'unreachable' => 'The image link could not be reached. Check the URL and try again.',
            'blocked' => 'The image link is not allowed.',
            'too_large' => 'The linked image is larger than 4 MB.',
            'not_image' => 'The link does not point to a supported image (JPG, PNG, GIF, or WebP).',
        ];

        if (isset($messages[$result])) {
            $fail($messages[$result]);
        }
    }

    private function shapeChecks(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Enter a valid image URL.';
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'The image link must start with http:// or https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The image link must not contain credentials.';
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            return 'The image link uses a disallowed port.';
        }

        return null;
    }

    private function staticChecks(string $url): ?string
    {
        $error = $this->shapeChecks($url);
        if ($error !== null) {
            return $error;
        }

        $parts = parse_url($url);
        if ($this->resolvePublicIps($parts['host']) === null) {
            return 'The image link points to a private or local address.';
        }

        return null;
    }

    private function resolvePublicIps(string $host): ?array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : null;
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (! empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        if (empty($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        return array_values($ips);
    }

    private function isPublicIp(string $ip): bool
    {
        foreach ([
            '100.64.0.0/10',
            '192.0.0.0/24',
            '198.18.0.0/15',
            '64:ff9b::/96',
            '2002::/16',
        ] as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return false;
            }
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        $addressBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);

        if ($addressBinary === false || $networkBinary === false
            || strlen($addressBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefix = (int) $prefix;
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($addressBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBinary[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
    }

    private function inspect(string $url): string
    {
        if (! function_exists('curl_init')) {
            return 'unreachable';
        }

        $deadline = microtime(true) + $this->totalTimeout;

        $head = $this->request($url, true, $deadline);
        if ($head['status'] === 'blocked') {
            return 'blocked';
        }

        $info = $head;
        if ($head['status'] !== 'ok' || $head['content_type'] === null) {
            $get = $this->request($url, false, $deadline);
            if ($get['status'] === 'blocked') {
                return 'blocked';
            }
            if ($get['status'] !== 'ok') {
                return 'unreachable';
            }
            $info = $get;
        }

        if ($info['http_code'] < 200 || $info['http_code'] >= 400) {
            return 'unreachable';
        }

        if ($info['length'] !== null && $info['length'] > $this->maxBytes) {
            return 'too_large';
        }

        $type = strtolower(trim(explode(';', (string) $info['content_type'])[0]));
        if ($type === '' || ! in_array($type, $this->allowedTypes, true)) {
            return 'not_image';
        }

        return 'ok';
    }

    private function request(string $url, bool $head, float $deadline): array
    {
        $current = $url;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $this->result('unreachable');
            }

            if ($this->staticChecks($current) !== null) {
                return $this->result('blocked');
            }

            $parts = parse_url($current);
            $host = $parts['host'];
            $scheme = strtolower($parts['scheme']);
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            $ips = $this->resolvePublicIps($host);
            if ($ips === null) {
                return $this->result('blocked');
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $current,
                CURLOPT_NOBODY => $head,
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, (int) ceil($remaining)),
                CURLOPT_TIMEOUT_MS => max(1, (int) floor($remaining * 1000)),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'FreeCause-ImageValidator/1.0',
                CURLOPT_MAXFILESIZE => $this->maxBytes,
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"],
            ]);

            if (! $head) {
                curl_setopt($ch, CURLOPT_RANGE, '0-1023');
            }

            $response = curl_exec($ch);
            if ($response === false) {
                curl_close($ch);

                return $this->result('unreachable');
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
            $lengthInfo = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $length = ($lengthInfo !== null && $lengthInfo >= 0) ? (int) $lengthInfo : null;
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
            curl_close($ch);

            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                $next = $this->resolveRedirect($current, $location);
                if ($next === null) {
                    return $this->result('blocked');
                }
                $current = $next;

                continue;
            }

            return [
                'status' => 'ok',
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'length' => $length,
            ];
        }

        return $this->result('unreachable');
    }

    private function resolveRedirect(string $base, ?string $location): ?string
    {
        if ($location === null || trim($location) === '') {
            return null;
        }
        $location = trim($location);

        $parts = parse_url($location);
        if ($parts === false) {
            return null;
        }

        if (! empty($parts['scheme'])) {
            return $location;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'].':'.$location;
        }

        $prefix = $baseParts['scheme'].'://'.$baseParts['host']
            .(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $prefix.$location;
        }

        $path = $baseParts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1) ?: '/';

        return $prefix.$dir.$location;
    }

    private function result(string $status): array
    {
        return ['status' => $status, 'http_code' => 0, 'content_type' => null, 'length' => null];
    }
}
