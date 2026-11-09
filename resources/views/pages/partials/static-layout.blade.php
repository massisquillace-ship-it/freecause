<x-ui.page-head :band="true" :title="$title" :breadcrumb="[
    ['label' => 'Home', 'url' => lroute('home')],
    ['label' => $title],
]" />

<x-ui.section>
    <div class="flex flex-wrap -mx-3">
        <div class="w-full shrink-0 grow-0 px-3">

            <div class="fcui-card relative flex flex-col min-w-0">
                <div class="grow shrink basis-auto p-4 sm:p-6">

                    @if (($fallback ?? false))
                        <div class="rounded-md border border-warning/30 bg-warning-soft px-4 py-3 text-sm text-warning mb-4" role="status">
                            {{ __('pages.lang_fallback_notice') }}
                        </div>
                    @endif

                    <h2 class="fcui-section-title capitalize text-lg">{{ $title }}</h2>

                    @if (($slug ?? null) === 'faqs')
                        <div class="mb-4" data-faq-search>
                            <label class="sr-only" for="faq-search">{{ __('pages.faq_search_label') }}</label>
                            <input id="faq-search" type="search" class="fcui-input w-full"
                                   placeholder="{{ __('pages.faq_search_placeholder') }}"
                                   autocomplete="off" data-faq-search-input>
                            <p class="mt-2 text-sm text-muted" data-faq-search-empty hidden>{{ __('pages.faq_search_empty') }}</p>
                        </div>
                        <div data-faq-search-root>
                            {!! \App\Services\ContentRenderer::renderFaqAccordion($content) !!}
                        </div>
                        @push('scripts')
                            <script src="{{ asset('legacy/js-v2/bootstrap.bundle.min.js') }}" defer></script>
                        @endpush
                    @else
                        <div class="fc-prose [&_h1]:text-lg [&_h1]:mt-8 [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:mt-8 [&_h2]:font-semibold [&_h3]:text-lg [&_h3]:mt-8 [&_h3]:font-semibold [&_ul]:pl-4 [&_ul]:mb-4">
                            {!! \App\Services\ContentRenderer::render($content) !!}
                        </div>
                    @endif

                </div>
            </div>

        </div>
    </div>

    <x-ads.rotate placement="inline" class="mt-6 text-center" />
    <x-ads.rotate placement="page_mid" class="mt-6" />
</x-ui.section>
