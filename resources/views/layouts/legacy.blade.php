<!doctype html>
<html lang="{{ app()->getLocale() }}" data-fc-ads="off">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @vite('resources/css/app.css')
    @stack('preload')
    @include('partials.consent')

    @php
        $locale = app()->getLocale();
        $fallbackLocale = \App\Support\Locale::default();

        $pageContentService = app(App\Services\PageContentService::class);
        $globalMeta = $pageContentService->getPage('global', $locale);
        $homeMeta = $pageContentService->getPage('home', $locale);
        $globalMetaDefault = $locale === $fallbackLocale ? $globalMeta : $pageContentService->getPage('global', $fallbackLocale);
        $homeMetaDefault = $locale === $fallbackLocale ? $homeMeta : $pageContentService->getPage('home', $fallbackLocale);

        $metaSuffix = $globalMeta['meta_title_suffix'] ?? ($globalMetaDefault['meta_title_suffix'] ?? ' - FreeCause');
        $metaDescription =
            $globalMeta['meta_description'] ??
            ($homeMeta['meta_description'] ??
            ($globalMetaDefault['meta_description'] ??
            ($homeMetaDefault['meta_description'] ??
            'FreeCause - Online petition platform to launch and support causes worldwide.')));
        $metaKeywords =
            $globalMeta['meta_keywords'] ??
            ($homeMeta['meta_keywords'] ??
            ($globalMetaDefault['meta_keywords'] ??
            ($homeMetaDefault['meta_keywords'] ??
            'petitions, activism, online petition, freecause')));
        $headExtra =
            $globalMeta['head_additional_html'] ??
            ($homeMeta['head_additional_html'] ??
            ($globalMetaDefault['head_additional_html'] ??
            ($homeMetaDefault['head_additional_html'] ?? '')));
        $metaDescription = \App\Support\Seo::description(get_defined_vars(), $metaDescription);
        $metaRobots = \App\Support\Seo::robots(get_defined_vars());
        $canonicalPageUrl = \App\Support\Seo::canonical();
        $canonical = trim((string) $__env->getSection('canonical', $canonicalPageUrl));

        $ogTitle = \App\Support\Seo::text($__env->getSection('og_title', $__env->getSection('title', 'FreeCause')));
        $ogDescription = \App\Support\Seo::text($__env->getSection('og_description', $metaDescription));
        $ogUrl = trim((string) $__env->getSection('og_url', $canonical));
        $ogType = trim((string) $__env->getSection('og_type', 'website'));
        $ogImage = trim((string) $__env->getSection('og_image', asset('legacy/images-v2/freecause_logo_icon_clear.png')));
        if ($ogImage !== '' && ! str_starts_with($ogImage, 'http://') && ! str_starts_with($ogImage, 'https://')) {
            $ogImage = url($ogImage);
        }
    @endphp

    <title>
        @hasSection('title')
            @php
                $yieldedTitle = \App\Support\Seo::text($__env->getSection('title'));
                $finalSuffix = str_contains(mb_strtolower($yieldedTitle), 'freecause') ? '' : $metaSuffix;
                $pageNumber = request()->query('page');
                if (\App\Support\Seo::isListing() && is_scalar($pageNumber) && ctype_digit((string) $pageNumber) && (int) $pageNumber > 1) {
                    $yieldedTitle .= ' · '.min((int) $pageNumber, 500);
                }
            @endphp
            {{ $yieldedTitle }}{{ $finalSuffix }}
        @else
            FreeCause{{ $metaSuffix }}
        @endif
    </title>

    <link rel="icon" type="image/png" href="{{ asset('legacy/images-v2/freecause_logo_icon_clear.png') }}">

    <meta name="description" content="{{ strip_tags($metaDescription) }}">
    <meta name="keywords" content="{{ strip_tags($metaKeywords) }}">

    <link rel="canonical" href="{{ $canonical }}">

    <meta name="robots" content="{{ $metaRobots }}">
    @unless (request()->routeIs('home', 'petition.show'))
        @include('partials.hreflang')
    @endunless

    <meta property="og:site_name" content="FreeCause">
    <meta property="og:locale" content="{{ \App\Support\Locale::toFull($locale) }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="{{ $ogTitle }}">
    <meta property="og:url" content="{{ $ogUrl }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    @php
        $headExtraHtml = \App\Services\ContentRenderer::render($headExtra);
        request()->attributes->set('head_additional_html', $headExtraHtml);
    @endphp
    {!! $headExtraHtml !!}

    @if (config('services.adsense.enabled') && config('services.adsense.client'))
        {{-- Keep legacy AdSense inert until a certified CMP is configured. --}}
        <script type="text/plain" data-fc-future-adsense data-src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ config('services.adsense.client') }}" crossorigin="anonymous"></script>
    @endif

    @vite('resources/js/app.js')

    @stack('head')

    @include('partials.analytics')
</head>

<body class="fc-public @yield('body_class', '')">
    <a href="#fc-main" class="fc-skip-link">{{ __('Skip to content') }}</a>
    @include('partials.navbar')

    @php
        $anchorSlot = \App\Support\Ads::pick('anchor');
    @endphp
    @if ($anchorSlot)
        <div id="fc-ad-anchor" class="fc-ad-anchor" role="complementary" aria-label="Advertisement">
            <button type="button" class="fc-ad-anchor__close" data-ad-anchor-close aria-label="Close advertisement">
                <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 2l12 12M14 2L2 14" /></svg>
            </button>
            <x-ads.slot :name="$anchorSlot" sticky class="mx-auto" />
        </div>
        <div class="fc-ad-anchor-spacer" aria-hidden="true"></div>
    @endif
    @unless (isset($exception))
        @if (\App\Support\Ads::canShow('skyscraper') && \App\Support\Ads::renderOnce('skyscraper'))
            <aside id="fc-ad-rail" class="fc-ad-rail" aria-label="Advertisement">
                <x-ads.slot name="skyscraper" claimed />
            </aside>
        @endif
    @endunless

    @php
        $announcementActive = \App\Support\Settings::get('announcement_active', false, 'global');
        $announcementText   = \App\Support\Settings::get('announcement_text', '', 'global');
    @endphp
    @if ($announcementActive && $announcementText)
        <div class="site-notice bg-brand text-white text-center px-4 py-2 text-sm font-semibold">
            {!! e($announcementText) !!}
        </div>
    @endif

    <main id="fc-main">
        @if (\App\Support\Ads::canShow('leaderboard') && ! request()->attributes->has('ads_slot_rendered_leaderboard'))
            <x-ads.slot name="leaderboard" class="hidden lg:block text-center my-4" />
        @endif

        @if (session('profile_completion'))
        <div class="bg-brand-soft border border-brand text-ink px-4 py-2 text-sm text-center" role="status">
            {{ session('profile_completion') }} <a href="{{ lroute('profile') }}" class="underline font-semibold">{{ __('profile.finish_profile') }}</a>
        </div>
        @endif
        @yield('content')

    </main>

    @unless(request()->routeIs('organizations.show'))
        <script src="{{ asset('legacy/js-v2/bootstrap.bundle.min.js') }}" defer></script>
    @endunless

    <script src="{{ asset('legacy/js-v2/form-busy.js') }}" defer></script>

    @stack('scripts')

    @if (isset($errors) && $errors->any())
        <div id="validation-errors" data-message="{{ $errors->first() }}" hidden></div>
    @endif

    @if (session('toast'))
        <div id="toast-flash" data-type="{{ session('toast')['type'] }}" data-message="{{ session('toast')['message'] }}" hidden></div>
    @endif

    @if (session('success'))
        <div id="flash-success" data-message="{{ session('success') }}" hidden></div>
    @endif

    @if (session('error'))
        <div id="flash-error" data-message="{{ session('error') }}" hidden></div>
    @endif

    {!! \App\Support\Settings::get('inject_body_html', '') !!}

    @include('partials.footer')
    @include('partials.mobile-nav')
</body>

</html>
