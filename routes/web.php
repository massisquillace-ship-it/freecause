<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminCategoriesController;
use App\Http\Controllers\Admin\AdminLanguagesController;
use App\Http\Controllers\Admin\AdminLogsController;
use App\Http\Controllers\Admin\AdminOrganizationsController;
use App\Http\Controllers\Admin\AdminPagesController;
use App\Http\Controllers\Admin\AdminPetitionDeleteController;
use App\Http\Controllers\Admin\AdminPetitionsController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\AdminReviewKeywordsController;
use App\Http\Controllers\Admin\AdminSignaturesController;
use App\Http\Controllers\Admin\AdminSpamController;
use App\Http\Controllers\Admin\AdminStatsController;
use App\Http\Controllers\Admin\AdminSystemController;
use App\Http\Controllers\Admin\AdminUserLevelsController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\AdsTxtController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GlobalOptionsController;
use App\Http\Controllers\Admin\LanguageOptionsController;
use App\Http\Controllers\Admin\System\PermissionController;
use App\Http\Controllers\AdsFrameController;
use App\Http\Controllers\AggregateTrafficController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryPetitionController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\GeoController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegacyShortLinkController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PetitionController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WidgetController;
use App\Http\Middleware\BlockBannedIp;
use App\Http\Middleware\SetLocale;
use App\Support\Settings;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::pattern('id', '[0-9]+');
Route::pattern('slug', '[^/]+');

Route::get('/widgets/pbadge/{petition}/{type}', [WidgetController::class, 'script'])
    ->where(['petition' => '[0-9]+', 'type' => '728x90|468x60|336x280|125x125']);
Route::get('/widget-render/pbadge/{petition}/{type}', [WidgetController::class, 'render'])
    ->where(['petition' => '[0-9]+', 'type' => '728x90|468x60|336x280|125x125']);

if (app()->environment('local', 'e2e')) {
    Route::view('/__components', 'dev.components');
}

Route::get('/', fn () => app(SetLocale::class)->autoRedirectToBrowserLocale(request()) ?? redirect('/'.default_locale()))
    ->name('root');

Route::get('/up', HealthController::class)->withoutMiddleware(BlockBannedIp::class);
Route::get('/version', VersionController::class);

$withoutSession = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
];

Route::get('/ads/frame/{slot}', AdsFrameController::class)->withoutMiddleware($withoutSession)->name('ads.frame');

Route::post('/site-metrics', AggregateTrafficController::class)
    ->withoutMiddleware([...$withoutSession, AuthenticateSession::class])
    ->middleware('throttle:120,1')->name('site.metrics');

Route::get('/speculationrules.json', function () {
    $urlExclusions = [
        ['not' => ['href_matches' => '/admin/*']],
        ['not' => ['href_matches' => '/api/*']],
        ['not' => ['href_matches' => '/*logout*']],
        ['not' => ['href_matches' => '*/download/*']],
        ['not' => ['href_matches' => '/widgets/*']],
        ['not' => ['href_matches' => '/widget-render/*']],
    ];

    $prerenderExclusions = [
        ['not' => ['href_matches' => '*/create-petition*']],
        ['not' => ['href_matches' => '*/sign*']],
    ];

    $selectorExclusions = [
        ['not' => ['selector_matches' => '[target=_blank]']],
        ['not' => ['selector_matches' => '[rel~=nofollow]']],
        ['not' => ['selector_matches' => '[data-no-prerender]']],
    ];

    return response()->json([
        'prerender' => [
            [
                'where' => [
                    'and' => array_merge(
                        [['href_matches' => '/*', 'relative_to' => 'document']],
                        $urlExclusions,
                        $prerenderExclusions,
                        $selectorExclusions
                    ),
                ],
                'eagerness' => 'moderate',
            ],
        ],
        'prefetch' => [
            [
                'where' => [
                    'and' => array_merge(
                        [['href_matches' => '/*', 'relative_to' => 'document']],
                        $urlExclusions,
                        $selectorExclusions
                    ),
                ],
                'eagerness' => 'conservative',
            ],
        ],
    ])->header('Content-Type', 'application/speculationrules+json');
})->name('speculation.rules');

Route::get('/ads.txt', function () {
    $content = Settings::get('ads_txt', '', 'global');

    return response($content, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
});

$toDefaultLocale = static function (string $path) {
    $target = '/'.default_locale().'/'.ltrim($path, '/');
    $query = request()->getQueryString();

    return redirect($target.($query ? '?'.$query : ''), 301);
};

Route::get('/petitions/{path?}', fn (?string $path = null) => $toDefaultLocale('petitions'.($path ? '/'.$path : '')))->where('path', '.*');
Route::get('/petition/{path?}', fn (?string $path = null) => $toDefaultLocale('petition'.($path ? '/'.$path : '')))->where('path', '.*');

$legacyUnprefixedWords = [
    'search', 'faq', 'faqs', 'news', 'topics', 'results', 'recover-password',
    'signatures', 'referers', 'download-signatures', 'invite-friends', 'tag', 'pic', 'feed',
    'user',
    'login', 'register', 'logout', 'profile', 'my-petitions', 'create-petition', 'edit-petition',
    'verify', 'reset-password', 'resend-verification', 'forgot-password', 'verify-email', 'email-verified',
    'contacts', 'donations', 'info', 'terms-of-service', 'privacy-policy', 'ethical-code',
    'how-to-write-a-petition', 'personal-data-processing',
    'ebay', 'zalando', 'amazon', 'expedia', 'booking', 'external',
];

Route::get('/{path?}', fn (?string $path = null) => $toDefaultLocale((string) $path))
    ->where('path', '^(?:'.implode('|', array_map('preg_quote', $legacyUnprefixedWords)).')(?:/.*)?$');

Route::withoutMiddleware($withoutSession)->group(function () {
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
    Route::get('/sitemap-petitions-{page}.xml', [SitemapController::class, 'petitions'])->where('page', '[0-9]{1,9}')->name('sitemap.petitions');
    Route::get('/sitemap-categories.xml', [SitemapController::class, 'categories'])->name('sitemap.categories');
    Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
});

Route::prefix('admin')->name('admin.')->middleware('no.cache')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'show'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('login.post')->middleware('throttle:admin-login');

    Route::middleware(['admin.auth', 'admin.audit'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/organizations', [AdminOrganizationsController::class, 'index'])->middleware('permission:organizations,view')->name('organizations');
        Route::get('/organizations/{organization}', [AdminOrganizationsController::class, 'show'])->whereNumber('organization')->middleware('permission:organizations,view')->name('organizations.show');
        Route::post('/organizations/{organization}/{action}', [AdminOrganizationsController::class, 'action'])->whereNumber('organization')->where('action', 'verify|reject|suspend|restore|founding|donation|reassign|identity')->middleware('permission:organizations,edit')->name('organizations.action');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/options/global', [GlobalOptionsController::class, 'edit'])->middleware('permission:options,view')->name('options.global');
        Route::post('/options/global', [GlobalOptionsController::class, 'update'])->middleware('permission:options,edit')->name('options.global.update');
        Route::post('/options/clear-cache', [GlobalOptionsController::class, 'clearCache'])->middleware('permission:options,edit')->name('options.clearCache');
        Route::get('/options/language', [LanguageOptionsController::class, 'edit'])->middleware('permission:options,view')->name('options.language');
        Route::post('/options/language/translation-key', [LanguageOptionsController::class, 'saveTranslationKey'])->middleware(['permission:options,edit', 'throttle:5,1'])->name('options.language.translationKey');
        Route::post('/options/language', [LanguageOptionsController::class, 'update'])->middleware('permission:options,edit')->name('options.language.update');
        Route::get('/ads', [AdsTxtController::class, 'edit'])->middleware('permission:options,view')->name('ads');
        Route::post('/ads', [AdsTxtController::class, 'update'])->middleware('permission:options,edit')->name('ads.update');

        Route::get('/users', [AdminUsersController::class, 'index'])->middleware('permission:users,view')->name('users');
        Route::get('/users/export', [AdminUsersController::class, 'export'])->middleware('permission:users,view')->name('users.export');
        Route::post('/users/save', [AdminUsersController::class, 'save'])->middleware('permission:users,edit')->name('users.save');
        Route::post('/users/bulk-banned', [AdminUsersController::class, 'bulkBan'])->middleware('permission:users,edit')->name('users.bulkBan');
        Route::post('/users/bulk-action', [AdminUsersController::class, 'bulkAction'])->middleware('permission:users,edit')->name('users.bulkAction');
        Route::post('/users/bulk-unban', [AdminUsersController::class, 'bulkUnban'])->middleware('permission:users,edit')->name('users.bulkUnban');
        Route::post('/users/{user}/ban', [AdminUsersController::class, 'ban'])->middleware('permission:users,edit')->name('users.ban');
        Route::post('/users/{user}/unban', [AdminUsersController::class, 'unban'])->middleware('permission:users,edit')->name('users.unban');
        Route::post('/users/bulk-delete', [AdminUsersController::class, 'bulkDelete'])->middleware('permission:users,delete')->name('users.bulkDelete');
        Route::post('/users/{user}/resend-verification', [AdminUsersController::class, 'resendVerification'])->middleware('permission:users,edit')->name('users.resendVerification');

        Route::get('/reports', [AdminReportsController::class, 'index'])->middleware('permission:reports,view')->name('reports');
        Route::post('/reports/status', [AdminReportsController::class, 'updateStatus'])->middleware('permission:reports,edit')->name('reports.status');

        Route::get('/petitions', [AdminPetitionsController::class, 'index'])->middleware('permission:petitions,view')->name('petitions');
        Route::post('/petitions/save', [AdminPetitionsController::class, 'save'])->middleware('permission:petitions,edit')->name('petitions.save');
        Route::post('/petitions/create', [AdminPetitionsController::class, 'create'])->middleware('permission:petitions,edit')->name('petitions.create');
        Route::post('/petitions/translate-missing', [AdminPetitionsController::class, 'translateMissing'])->middleware('permission:petitions,edit')->name('petitions.translateMissing');
        Route::post('/petitions/bulk-action', [AdminPetitionsController::class, 'bulkAction'])->middleware('permission:petitions,edit')->name('petitions.bulkAction');
        Route::post('/petitions/bulk-delete', AdminPetitionDeleteController::class)->middleware('permission:petitions,delete')->name('petitions.bulkDelete');
        Route::post('/petitions/reconcile', [AdminPetitionsController::class, 'reconcile'])->middleware('permission:petitions,edit')->name('petitions.reconcile');
        Route::post('/petitions/reconcile-all', [AdminPetitionsController::class, 'reconcileAll'])->middleware('permission:petitions,edit')->name('petitions.reconcileAll');

        Route::get('/categories', [AdminCategoriesController::class, 'index'])->middleware('permission:categories,view')->name('categories');
        Route::post('/categories/save', [AdminCategoriesController::class, 'save'])->middleware('permission:categories,edit')->name('categories.save');
        Route::post('/categories/create', [AdminCategoriesController::class, 'createCategory'])->middleware('permission:categories,edit')->name('categories.create');
        Route::post('/categories/delete', [AdminCategoriesController::class, 'destroy'])->middleware('permission:categories,delete')->name('categories.destroy');

        Route::get('/signatures', [AdminSignaturesController::class, 'index'])->middleware('permission:signatures,view')->name('signatures');
        Route::post('/signatures/bulk-action', [AdminSignaturesController::class, 'bulkAction'])->middleware('permission:signatures,view')->name('signatures.bulkAction');
        Route::post('/signatures/{signature}/action', [AdminSignaturesController::class, 'action'])->middleware('permission:signatures,edit')->name('signatures.action');

        Route::get('/pages', [AdminPagesController::class, 'index'])->middleware('permission:pages,view')->name('pages');
        Route::post('/pages/save', [AdminPagesController::class, 'save'])->middleware('permission:pages,edit')->name('pages.save');
        Route::post('/pages/bulk-action', [AdminPagesController::class, 'bulkAction'])->middleware('permission:pages,view')->name('pages.bulkAction');

        Route::get('/keywords', [AdminReviewKeywordsController::class, 'index'])->middleware('permission:spam,view')->name('keywords');
        Route::post('/keywords/save', [AdminReviewKeywordsController::class, 'save'])->middleware('permission:spam,edit')->name('keywords.save');
        Route::post('/keywords/reset', [AdminReviewKeywordsController::class, 'reset'])->middleware('permission:spam,edit')->name('keywords.reset');
        Route::post('/keywords/concept', [AdminReviewKeywordsController::class, 'toggleConcept'])->middleware('permission:spam,edit')->name('keywords.concept');
        Route::post('/keywords/rescan', [AdminReviewKeywordsController::class, 'rescan'])->middleware(['permission:spam,edit', 'permission:petitions,edit'])->name('keywords.rescan');

        Route::get('/spam', [AdminSpamController::class, 'index'])->middleware('permission:spam,view')->name('spam');
        Route::post('/spam/ban', [AdminSpamController::class, 'ban'])->middleware('permission:spam,edit')->name('spam.ban');
        Route::post('/spam/unban', [AdminSpamController::class, 'unban'])->middleware('permission:spam,edit')->name('spam.unban');
        Route::post('/spam/clear', [AdminSpamController::class, 'clear'])->middleware('permission:spam,delete')->name('spam.clear');

        Route::get('/stats', [AdminStatsController::class, 'index'])->middleware('permission:stats,view')->name('stats');

        Route::get('/logs', [AdminLogsController::class, 'index'])->middleware('permission:logs,view')->name('logs');
        Route::get('/logs/export', [AdminLogsController::class, 'export'])->middleware('permission:logs,view')->name('logs.export');
        Route::post('/logs/prune', [AdminLogsController::class, 'prune'])->middleware('permission:logs,delete')->name('logs.prune');
        Route::post('/logs/bulk-delete', [AdminLogsController::class, 'bulkDelete'])->middleware('permission:logs,delete')->name('logs.bulkDelete');
        Route::post('/logs/restore', [AdminLogsController::class, 'restore'])->middleware('permission:logs,delete')->name('logs.restore');
        Route::post('/logs/restore-all', [AdminLogsController::class, 'restoreAll'])->middleware('permission:logs,delete')->name('logs.restoreAll');

        Route::get('/languages', [AdminLanguagesController::class, 'index'])->middleware('permission:languages,view')->name('languages.index');
        Route::post('/languages', [AdminLanguagesController::class, 'store'])->middleware('permission:languages,edit')->name('languages.store');
        Route::put('/languages/{language}', [AdminLanguagesController::class, 'update'])->middleware('permission:languages,edit')->name('languages.update');
        Route::post('/languages/{language}/default', [AdminLanguagesController::class, 'setDefault'])->middleware('permission:languages,edit')->name('languages.default');
        Route::delete('/languages/{language}', [AdminLanguagesController::class, 'destroy'])->middleware('permission:languages,delete')->name('languages.destroy');

        Route::get('/system/user-info', [AdminSystemController::class, 'userInfo'])->middleware('permission:system,manage')->name('system.user_info');
        Route::post('/system/user-info', [AdminSystemController::class, 'updateUserInfo'])->middleware('permission:system,manage')->name('system.user_info.update');
        Route::get('/system/user-levels', [AdminUserLevelsController::class, 'index'])->middleware('permission:system,manage')->name('system.user_levels');
        Route::post('/system/user-levels', [AdminUserLevelsController::class, 'store'])->middleware('permission:system,manage')->name('system.user_levels.store');
        Route::post('/system/user-levels/delete', [AdminUserLevelsController::class, 'delete'])->middleware('permission:system,manage')->name('system.user_levels.delete');
        Route::get('/system/permissions', [PermissionController::class, 'index'])->middleware('permission:system,manage')->name('system.permissions');
        Route::post('/system/permissions', [PermissionController::class, 'store'])->middleware('permission:system,manage')->name('system.permissions.store');
    });
});

// Legacy six-character short links (e.g. /GBFS45). Registered before the
// {locale} group so a short link is not mistaken for a locale.
Route::get('/{shortLink}', LegacyShortLinkController::class)
    ->where('shortLink', '[0-9A-Z]{6}')
    ->name('legacy.shortlink');

Route::prefix('{locale}')
    ->middleware(['optional.session', 'setLocale', 'block.banned.user'])
    ->group(function () use ($withoutSession) {
        Route::get('/geo/cities', [GeoController::class, 'cities'])->withoutMiddleware($withoutSession)->middleware('throttle:30,1')->name('geo.cities');
        Route::get('/petitions', [PetitionController::class, 'index'])
            ->withoutMiddleware($withoutSession)
            ->name('petitions.index');

        Route::get('/petitions/category-{categorySlug}-{category}', [CategoryPetitionController::class, 'index'])
            ->where([
                'categorySlug' => '[^/]+',
                'category' => '[0-9]+',
            ])
            ->withoutMiddleware($withoutSession)
            ->name('petitions.byCategory');
    });

Route::prefix('{locale}')
    ->middleware(['setLocale', 'block.banned.user'])
    ->group(function () use ($withoutSession) {
        Route::get('/organizations', [OrganizationController::class, 'landing'])->name('organizations');
        Route::get('/organizations/terms', [OrganizationController::class, 'terms'])->name('organizations.terms');
        Route::get('/org/{slug}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::middleware(['auth', 'profile.complete', 'no.cache'])->group(function () {
            Route::get('/organizations/apply', [OrganizationController::class, 'apply'])->name('organizations.apply');
            Route::post('/organizations/apply', [OrganizationController::class, 'store'])->middleware('throttle:5,1')->name('organizations.store');
            Route::get('/org/{slug}/manage', [OrganizationController::class, 'manage'])->name('organizations.manage');
            Route::get('/org/{slug}/manage/profile', [OrganizationController::class, 'edit'])->name('organizations.edit');
            Route::post('/org/{slug}/manage/profile', [OrganizationController::class, 'update'])->middleware('throttle:10,1')->name('organizations.update');
            Route::post('/org/{slug}/manage/resubmit', [OrganizationController::class, 'resubmit'])->middleware('throttle:5,1')->name('organizations.resubmit');
        });

        Route::get('/oauth/google', [GoogleAuthController::class, 'redirect'])->name('oauth.google');
        Route::get('/oauth/google/callback', [GoogleAuthController::class, 'callback'])->name('oauth.google.callback');

        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:6,1');
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->name('register.post')->middleware('throttle:5,1');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

        Route::get('/verify/{token}', [AuthController::class, 'verify'])->name('verify.account');

        Route::get('/user/{slug}/{id}', [UserProfileController::class, 'show'])->name('user.profile');

        Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
        Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:6,1');
        Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update')->middleware('throttle:6,1');
        Route::get('/resend-verification', [AuthController::class, 'showResendVerification'])->name('verification.resend.form');
        Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->name('verification.resend')->middleware('throttle:6,1');
        Route::get('/verify-email', [AuthController::class, 'showVerifyNotice'])->name('verification.notice');
        Route::get('/email-verified', [AuthController::class, 'showVerified'])->name('verification.verified');

        Route::middleware(['auth', 'profile.complete'])->group(function () {
            Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
            Route::post('/profile', [AuthController::class, 'updateProfile'])->name('account.profile.update')->middleware('throttle:10,1');
            Route::post('/account/delete', [AuthController::class, 'delete'])
                ->middleware('throttle:5,1')
                ->name('account.delete');
            Route::get('/account/delete/google', [GoogleAuthController::class, 'redirectForAccountDeletion'])
                ->middleware('throttle:5,1')
                ->name('account.delete.google');
            Route::get('/my-petitions', [PetitionController::class, 'myPetitions'])->name('account.petitions');

            Route::post('/account/unlink/google', [AuthController::class, 'unlinkGoogle'])->name('account.unlink.google');

            Route::post('/create-petition', [PetitionController::class, 'store'])->name('petition.store')->middleware('throttle:5,1');

            Route::get('/petition/{slug}/{id}/edit', [PetitionController::class, 'edit'])->name('petition.edit');
            Route::post('/petition/{slug}/{id}/edit', [PetitionController::class, 'update'])->name('petition.update')->middleware('throttle:10,1');
            Route::post('/petition/{slug}/{id}/withdraw', [PetitionController::class, 'withdraw'])->name('petition.withdraw')->middleware('throttle:10,1');
            Route::get('/petition/{slug}/{id}/download/txt', [PetitionController::class, 'downloadTxt'])->name('petition.download.txt');
            Route::get('/petition/{slug}/{id}/download/pdf', [PetitionController::class, 'downloadPdf'])->name('petition.download.pdf');
            Route::get('/petition/{slug}/{id}/download/csv', [PetitionController::class, 'downloadCsv'])->name('petition.download.csv');
        });

        Route::get('/faqs', fn (string $locale) => app(PageController::class)->showFixed($locale, 'faqs'))
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->name('faqs');
        Route::get('/how-to-write-a-petition', fn (string $locale) => app(PageController::class)->showFixed($locale, 'how-to-write-a-petition'))
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->name('how-to-write-a-petition');
        Route::get('/donations', fn (string $locale) => app(PageController::class)->showFixed($locale, 'donations'))
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->name('donations');
        Route::get('/personal-data-processing', fn (string $locale) => app(PageController::class)->showFixed($locale, 'personal-data-processing'))
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->name('personal-data-processing');

        Route::get('/contacts', fn () => view('pages.contacts'))->name('contacts');
        Route::post('/contacts', [ContactController::class, 'send'])->name('contacts.submit')->middleware('throttle:6,1');

        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::get('/create-petition', [PetitionController::class, 'create'])->name('petition.create');

        Route::get('/petition/{slug}/{id}', [PetitionController::class, 'show'])
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->name('petition.show');
        Route::get('/petition/{slug}/{id}/signatures', [PetitionController::class, 'signatures'])->name('petition.signatures');
        Route::match(['get', 'post'], '/petition/{slug}/{id}/sign/confirm', [PetitionController::class, 'signPage'])->name('petition.sign.page')->middleware('profile.complete');
        Route::post('/petition/{slug}/{id}/sign', [PetitionController::class, 'sign'])->name('petition.sign')->middleware(['throttle:10,1', 'profile.complete']);
        Route::post('/petition/{slug}/{id}/shoutbox', [PetitionController::class, 'shoutboxStore'])->name('petition.shoutbox')->middleware(['auth', 'profile.complete', 'throttle:10,1']);
        Route::get('/petition/{slug}/{id}/thanks', [PetitionController::class, 'thanks'])->name('petition.thanks');
        Route::get('/petition/{slug}/{id}/thanksforsigning/{status?}', function (string $locale, string $slug, int $id, $status = null) {
            $mode = $status === 'created' ? 'created' : session('petition_mode', 'signed');
            $verified = session('petition_verified', (string) ($status ?? '1') !== '0');

            return redirect()->route('petition.thanks', ['locale' => $locale, 'slug' => $slug, 'id' => $id], 301)
                ->with('petition_mode', $mode)->with('petition_verified', $verified);
        })->where('status', '([0-9]+|created)?')->name('petition.thanks.legacy');
        Route::get('/privacy-policy-12', fn (string $locale) => redirect('/'.$locale.'/privacy-policy', 301));

        require __DIR__.'/reports.php';
        require __DIR__.'/preferences.php';

        Route::get('/{slug}', [PageController::class, 'show'])
            ->withoutMiddleware($withoutSession)
            ->middleware('optional.session')
            ->where('slug', '[^{}/]+')
            ->name('page.show');
    });
