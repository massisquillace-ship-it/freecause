@php
    $locale = app()->getLocale();
    $fallbackLocale = config('app.fallback_locale', 'en');

    $service = app(App\Services\PageContentService::class);

    $layoutContent = $service->getPageWithFallback('layout', $locale, $fallbackLocale);

    $about  = $layoutContent['footer_about'] ?? '';
    $bottom = $layoutContent['footer_bottom'] ?? '';

    $personalDataTitle = cache()->remember("page_title_personal_data_processing_{$locale}", 3600, fn () => App\Models\PageTranslation::query()
        ->where('slug', 'personal-data-processing')
        ->where('locale', $locale)
        ->value('title') ?? 'Personal Data Processing');

    $footerLinks = [
        ['label' => __('nav.home'), 'href' => lroute('home')],
        ['label' => __('nav.explore'), 'href' => lroute('petitions.index')],
        ['label' => __('pages.how_to_write_a_petition'), 'href' => lroute('how-to-write-a-petition')],
        ['label' => __('pages.donations'), 'href' => lroute('donations')],
        ['label' => __('pages.faqs'), 'href' => lroute('faqs')],
        ['label' => __('contacts.title'), 'href' => lroute('contacts')],
        ['label' => __('pages.ethical_code'), 'href' => lroute('page.show', ['slug' => 'ethical-code'])],
        ['label' => __('pages.terms_of_service'), 'href' => lroute('page.show', ['slug' => 'terms-of-service'])],
        ['label' => __('pages.privacy_policy'), 'href' => lroute('page.show', ['slug' => 'privacy-policy'])],
        ['label' => $personalDataTitle, 'href' => lroute('personal-data-processing')],
    ];
    if (\App\Models\Organization::directoryReady()) {
        array_splice($footerLinks, 2, 0, [
            ['label' => __('home.for_organizations'), 'href' => lroute('organizations')],
            ['label' => __('organizations.claim_organization'), 'href' => lroute('organizations.claim-search')],
        ]);
    }
@endphp

<section class="site-footer bg-dark text-on-dark font-[Arial,sans-serif]">
    <div class="mx-auto w-full px-3 max-w-[1300px]">
        <div class="flex flex-wrap -mx-3">
            <div class="w-full shrink-0 grow-0 px-3">

                <div class="p-10 max-md:p-6 max-sm:p-4 max-md:text-center">

                    <div class="[&_.footer-logo_img]:brightness-0 [&_.footer-logo_img]:invert [&_img]:max-w-[306px] [&_img]:w-full [&_img]:h-auto [&_img]:mb-5 max-md:[&_img]:mx-auto max-md:[&_img]:max-w-[220px] [&_p]:text-sm [&_p]:mb-0 [&_p]:text-on-dark [&_a]:text-on-dark [&_a]:underline [&_a]:break-words">
                        {!! \App\Services\ContentRenderer::render($about) !!}
                    </div>

                    <nav aria-label="{{ __('nav.menu') }}" class="pt-5 pb-8 mt-8 border-t border-on-dark/20">
                        <ul class="flex flex-row flex-wrap items-center gap-x-5 gap-y-2 m-0 p-0 list-none max-md:flex-col">
                            @foreach ($footerLinks as $link)
                                <li>
                                    <a href="{{ $link['href'] }}" class="text-on-dark no-underline text-sm hover:underline">
                                        {{ $link['label'] }}
                                    </a>
                                </li>
                            @endforeach
                            <li>
                                <a href="{{ lroute('page.show', ['slug'=>'privacy-policy']) }}" data-fc-cookie-settings data-cc="show-preferencesModal" class="text-on-dark text-sm underline">Cookies</a>
                            </li>
                        </ul>
                    </nav>

                </div>

                <div class="border-t border-on-dark/20 text-center p-5 max-sm:p-4 text-sm text-on-dark">
                    {!! \App\Services\ContentRenderer::render($bottom) !!}
                </div>

            </div>
        </div>
    </div>
</section>
