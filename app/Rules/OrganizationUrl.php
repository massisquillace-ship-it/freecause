<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OrganizationUrl implements ValidationRule
{
    public const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com'], 'instagram' => ['instagram.com'],
        'x' => ['x.com', 'twitter.com'], 'linkedin' => ['linkedin.com'],
        'youtube' => ['youtube.com', 'youtu.be'], 'tiktok' => ['tiktok.com'],
    ];

    public function __construct(private array $hosts = [], private bool $external = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail(__('organizations.invalid_url'));

            return;
        }
        $url = parse_url($value);
        $host = strtolower($url['host'] ?? '');
        $valid = ($url['scheme'] ?? '') === 'https' && $host !== ''
            && ! isset($url['user']) && ! isset($url['pass'])
            && (! isset($url['port']) || $url['port'] === 443)
            && ! str_contains($value, chr(92)) && ! preg_match('/\s/', $value);
        if ($this->hosts) {
            $valid = $valid && collect($this->hosts)->contains(fn ($root) => $host === $root || str_ends_with($host, '.'.$root));
        }
        if ($this->external) {
            $valid = $valid && $host !== 'freecause.com' && ! str_ends_with($host, '.freecause.com');
        }
        if (! $valid) {
            $fail(__('organizations.invalid_url'));
        }
    }
}
