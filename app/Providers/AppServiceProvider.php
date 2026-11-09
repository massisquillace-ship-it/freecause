<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use App\Observers\FlushesListingCaches;
use App\Observers\UserProfileCacheFlusher;
use App\Services\PageContentService;
use App\Support\Locale;
use App\Support\MailQuota;
use App\Support\MailText;
use App\Support\Settings;
use App\Support\TranslationJoin;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {

        EloquentBuilder::macro('withTranslation', function (array $config) {
            return TranslationJoin::apply($this, $config);
        });

        QueryBuilder::macro('withTranslation', function (array $config) {
            return TranslationJoin::apply($this, $config);
        });

        Petition::observe(FlushesListingCaches::class);
        Category::observe(FlushesListingCaches::class);
        PetitionTranslation::observe(FlushesListingCaches::class);
        CategoryTranslation::observe(FlushesListingCaches::class);
        User::observe(UserProfileCacheFlusher::class);

        Queue::before(fn () => Settings::flushMemo());

        $this->bootRateLimiting();

        $this->bootMailQuotaGuard();

        $this->bootPhpVersionGuard();

        $this->bootLocale();
        $this->bootViewComposers();
        $this->bootMail();
        $this->bootOAuth();
        $this->bootBrandedResetMail();
    }

    private function bootPhpVersionGuard(): void
    {
        $minimum = (string) config('app.min_php_version', '8.3.36');

        if (version_compare(PHP_VERSION, $minimum, '>=')) {
            return;
        }

        $message = 'PHP '.PHP_VERSION." is below the patched minimum {$minimum} (php2shell: CVE-2026-109618, CVE-2026-109619)";

        if (! config('app.enforce_min_php')) {
            Log::critical($message);

            return;
        }

        Log::critical($message);

        abort(503, $message);
    }

    private static function normalizedInput(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    private function bootRateLimiting(): void
    {
        try {
            RateLimiter::for('admin-login', function (Request $request) {
                $limits = [Limit::perMinute(10)->by('admin-login-ip:'.$request->ip())];
                $login = self::normalizedInput($request->input('login'));
                if ($login !== '') {
                    $limits[] = Limit::perMinute(10)->by('admin-login-email:'.$login);
                }

                return $limits;
            });
            RateLimiter::for('login', function (Request $request) {
                $limits = [Limit::perMinute(6)->by('login-ip:'.$request->ip())];
                $email = self::normalizedInput($request->input('email'));
                if ($email !== '') {
                    $limits[] = Limit::perMinute(6)->by('login-email:'.$email);
                }

                return $limits;
            });
            RateLimiter::for('auth-mail', function (Request $request) {
                $route = (string) $request->route()?->getName();
                $limits = [Limit::perMinute(6)->by('auth-mail-ip:'.$route.':'.$request->ip())];
                $email = self::normalizedInput($request->input('email'));
                if ($email !== '') {
                    $limits[] = Limit::perHour(3)->by('auth-mail-email:'.$route.':'.$email);
                }

                return $limits;
            });
        } catch (\Throwable $e) {
            Log::warning('bootRateLimiting failed: '.$e->getMessage());
        }
    }

    private function bootLocale(): void
    {
        try {
            $segmentLocale = request()->segment(1);

            $availableLocales = Locale::supported();
            $defaultLocale = Locale::default();

            if (in_array($segmentLocale, $availableLocales)) {
                App::setLocale($segmentLocale);
                URL::defaults(['locale' => $segmentLocale]);
            } else {
                App::setLocale($defaultLocale);
                URL::defaults(['locale' => $defaultLocale]);
            }

            try {
                $baseUrl = Settings::get('base_url', '', 'global');
                if (! empty($baseUrl)) {
                    URL::forceRootUrl(rtrim($baseUrl, '/'));
                    if (str_starts_with($baseUrl, 'https://')) {
                        URL::forceScheme('https');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('bootLocale: could not apply base_url: '.$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error('bootLocale failed, falling back to en: '.$e->getMessage());
            App::setLocale('en');
            URL::defaults(['locale' => 'en']);
        }
    }

    private function bootViewComposers(): void
    {
        try {
            $shortBaseUrl = Settings::get('short_base_url', '', 'global');
            View::share('shortBaseUrl', $shortBaseUrl ?: config('app.url'));
        } catch (\Throwable $e) {
            Log::warning('bootViewComposers: could not load short_base_url: '.$e->getMessage());
            View::share('shortBaseUrl', config('app.url'));
        }

        View::composer('partials.navbar', function ($view) {
            try {
                $locale = app()->getLocale();

                $defaultLocale = Locale::default();

                $pageContent = app(PageContentService::class)->getPage('navbar', $locale);

                $categories = Cache::remember(
                    "categories:list:$locale",
                    now()->addHours(12),
                    function () use ($locale, $defaultLocale) {
                        return Category::query()
                            ->select(['categories.id'])
                            ->withTranslation([
                                'base' => 'categories',
                                'base_key' => 'id',
                                'translation_table' => 'category_translations',
                                'translation_key' => 'category_id',
                                'locale_alias' => 'ct_locale',
                                'default_alias' => 'ct_default',
                                'locale' => $locale,
                                'default_locale' => $defaultLocale,
                                'columns' => ['name' => 'name', 'slug' => 'slug'],
                            ])
                            ->where('categories.is_active', true)
                            ->orderBy('categories.id')
                            ->get();
                    }
                );

                $view->with([
                    'pageContent' => $pageContent,
                    'navbarContent' => $pageContent,
                    'categories' => $categories,
                ]);
            } catch (\Throwable $e) {
                Log::warning('navbar composer failed: '.$e->getMessage());
                $view->with([
                    'pageContent' => collect(),
                    'categories' => collect(),
                    'navbarContent' => collect(),
                ]);
            }
        });
    }

    private function bootMailQuotaGuard(): void
    {
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            try {
                if (MailQuota::causedBy($event->exception)) {
                    MailQuota::trip();
                }
            } catch (\Throwable $e) {
                Log::warning('Mail quota guard failed: '.$e->getMessage());
            }
        });
    }

    private function bootMail(): void
    {
        try {
            if (! Settings::get('smtp_enabled', false)) {
                return;
            }

            $encryption = Settings::get('smtp_encryption', 'tls');
            $host = Settings::get('smtp_host', '');
            $user = Settings::get('smtp_user', '');
            $pass = Settings::get('smtp_pass', '');
            $fromEmail = Settings::get('email_from', config('mail.from.address'));

            if (empty($host)) {
                return;
            }

            // use explicit setting, then env, then derive from encryption
            $settingsPort = (int) Settings::get('smtp_port', 0);
            $port = $settingsPort > 0
                ? $settingsPort
                : match ($encryption) {
                    'tls' => 587,
                    'ssl' => 465,
                    default => (int) env('MAIL_PORT', 587),
                };

            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $host);
            Config::set('mail.mailers.smtp.port', $port);
            Config::set('mail.mailers.smtp.username', $user);
            Config::set('mail.mailers.smtp.password', $pass);
            Config::set('mail.mailers.smtp.encryption', $encryption ?: null);
            Config::set('mail.from.address', $fromEmail ?: config('mail.from.address'));
            Config::set('mail.from.name', config('app.name'));
        } catch (\Throwable $e) {
            Log::warning('bootMail failed: '.$e->getMessage());
        }
    }

    private function bootOAuth(): void
    {
        try {
            $clientId = trim((string) Settings::get('google_client_id', ''));
            $clientSecret = trim((string) Settings::get('google_client_secret', ''));

            if ($clientId !== '') {
                Config::set('services.google.client_id', $clientId);
            }

            if ($clientSecret !== '') {
                Config::set('services.google.client_secret', $clientSecret);
            }
        } catch (\Throwable $e) {
            Log::warning('bootOAuth Google failed: '.$e->getMessage());
        }

    }

    private function bootBrandedResetMail(): void
    {
        try {
            ResetPassword::toMailUsing(function ($notifiable, $token) {
                $locale = $notifiable->preferredLocale();

                return (new MailMessage)
                    ->subject(MailText::get('reset_subject', [], $locale))
                    ->view('emails.reset-password', [
                        'firstName' => $notifiable->first_name ?: ($notifiable->name ?? ''),
                        'emailLocale' => $locale,
                        'resetMinutes' => config('auth.passwords.users.expire', 60),
                        'resetUrl' => $this->buildPasswordResetUrl($notifiable, $token),
                        'email' => $notifiable->getEmailForPasswordReset(),
                    ]);
            });
        } catch (\Throwable $e) {
            Log::warning('bootBrandedResetMail failed: '.$e->getMessage());
        }
    }

    private function buildPasswordResetUrl($notifiable, string $token): string
    {
        return url(route('password.reset', [
            'locale' => $notifiable->preferredLocale(),
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
