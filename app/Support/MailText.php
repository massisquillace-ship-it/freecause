<?php

namespace App\Support;

use App\Services\PageContentService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class MailText
{
    public static function defaults(string $locale): array
    {
        $values = [];
        foreach (Arr::dot(trans('emails', [], $locale)) as $key => $value) {
            if (is_string($value)) {
                $values[str_replace('.', '_', $key)] = $value;
            }
        }
        foreach (['subject', 'intro', 'body', 'action', 'footer'] as $key) {
            $values['welcome_'.$key] = trans('profile.welcome_'.$key, [], $locale);
        }

        foreach (trans('organizations.mail', [], $locale) as $key => $value) {
            $values['organization_'.$key] = $value;
        }

        $values['verify_heading'] = $values['verify_subject'];
        $values['verify_footer'] = trans('profile.welcome_footer', [], $locale);
        $values['common_petition'] = trans('common.petition', [], $locale);
        $values['common_greeting'] = trans('emails.petition_digest.greeting', [], $locale);
        $values['common_footer'] = trans('profile.welcome_footer', [], $locale);
        $values['common_manage_preferences'] = trans('preferences.manage_preferences', [], $locale);

        return $values;
    }

    public static function catalog(string $locale): array
    {
        $defaults = self::defaults($locale);
        $saved = app(PageContentService::class)->getPage('emails', $locale);

        return array_replace($defaults, array_intersect_key($saved, $defaults));
    }

    public static function clear(string $locale): void
    {
        Cache::forget('page_content_emails_'.$locale);
    }

    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $defaults = self::defaults($locale);
        try {
            $saved = app(PageContentService::class)->getPage('emails', $locale);
        } catch (\Throwable) {
            $saved = [];
        }
        $text = filled($saved[$key] ?? null) ? $saved[$key] : ($defaults[$key] ?? '');
        $tokens = [];
        foreach ($replace as $name => $value) {
            $tokens[':'.$name] = (string) $value;
        }

        return strtr($text, $tokens);
    }
}
