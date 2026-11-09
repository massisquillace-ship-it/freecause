@extends('layouts.legacy')

@section('title', ($pageTitle ?? __('list.petitions')) . ' - FreeCause')

@section('content')
    <section class="fc-section">
        <x-ui.container>

            <nav aria-label="{{ __('ui.breadcrumb') }}" class="mb-4">
                <ol class="breadcrumb text-sm mb-0">
                    <li class="breadcrumb-item">
                        <a href="{{ lroute('home') }}" class="text-brand">{{ __('show.breadcrumb_home') }}</a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $heading ?? __('list.petitions') }}</li>
                </ol>
            </nav>

            <div class="bg-white shadow-elev-1 rounded-lg p-4">

                <div class="flex items-center justify-between flex-wrap gap-2 mb-2 max-md:contents">
                    <h1 class="text-base leading-normal m-0 font-bold break-words">{{ $heading ?? __('list.petitions') }}</h1>
                    <form method="GET" action="" data-live-search class="flex gap-2 w-full max-w-[300px] max-md:max-w-full max-md:bg-white max-md:py-2">
                        <input type="text" name="q" value="{{ request('q') }}"
                               placeholder="{{ __('list.search_placeholder') }}"
                               aria-label="{{ __('list.search') }}"
                               class="block w-full px-2 py-1 text-sm leading-normal text-ink bg-white border border-border rounded-md appearance-none max-md:min-h-12 max-md:text-base focus:border-brand-light focus:outline-none focus:ring-4 focus:ring-brand/25">
                        <button type="submit" class="inline-flex items-center justify-center px-2 py-1 bg-brand text-white border border-brand rounded-xs cursor-pointer max-md:min-h-12 max-md:min-w-12 hover:bg-brand-hover" aria-label="{{ __('list.search') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        </button>
                        @if(request('q'))
                            <a href="{{ request()->url() }}" class="inline-flex items-center justify-center px-2 py-1 text-muted bg-white border border-muted rounded-xs no-underline max-md:min-h-12 max-md:min-w-12 hover:bg-muted hover:text-white"
                               aria-label="{{ __('list.clear') }}" title="{{ __('list.clear') }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </a>
                        @endif
                    </form>
                </div>

                <div class="h-0.5 bg-border relative mb-6">
                    <div
                        class="h-0.5 w-full bg-gradient-to-r from-dark to-brand absolute left-0 top-0">
                    </div>
                </div>

                @if (! $search && isset($sort))
                    @php
                        $sortBase = request()->except(['sort', 'page']);
                        $sortNewestUrl = request()->fullUrlWithQuery(array_merge($sortBase, ['sort' => 'newest']));
                        $sortSignedUrl = request()->fullUrlWithQuery(array_merge($sortBase, ['sort' => 'signed']));
                        $sortTrendingUrl = request()->fullUrlWithQuery(array_merge($sortBase, ['sort' => 'trending']));
                    @endphp
                    <div class="flex items-center flex-wrap gap-2 mb-4 max-md:hidden">
                        <span class="text-sm text-muted me-1">{{ __('list.sort_by') }}:</span>
                        <a href="{{ $sortNewestUrl }}"
                           class="inline-flex items-center px-3 py-1 rounded-xs text-sm no-underline border transition-colors duration-150 {{ $sort === 'newest' ? 'bg-brand text-white border-brand' : 'bg-white text-brand border-border hover:border-brand' }}"
                           @if ($sort === 'newest') aria-current="true" @endif>{{ __('list.sort_newest') }}</a>
                        <a href="{{ $sortTrendingUrl }}"
                           class="inline-flex items-center px-3 py-1 rounded-xs text-sm no-underline border transition-colors duration-150 {{ $sort === 'trending' ? 'bg-brand text-white border-brand' : 'bg-white text-brand border-border hover:border-brand' }}"
                           @if ($sort === 'trending') aria-current="true" @endif>{{ __('list.sort_trending') }}</a>
                        <a href="{{ $sortSignedUrl }}"
                           class="inline-flex items-center px-3 py-1 rounded-xs text-sm no-underline border transition-colors duration-150 {{ $sort === 'signed' ? 'bg-brand text-white border-brand' : 'bg-white text-brand border-border hover:border-brand' }}"
                           @if ($sort === 'signed') aria-current="true" @endif>{{ __('list.sort_signed') }}</a>
                    </div>

                    <x-ui.action-sheet id="sort-sheet" :title="__('list.sort_by')" :actions="[
                        ['href' => $sortNewestUrl, 'label' => __('list.sort_newest'), 'icon' => $sort === 'newest' ? 'check' : 'circle'],
                        ['href' => $sortTrendingUrl, 'label' => __('list.sort_trending'), 'icon' => $sort === 'trending' ? 'check' : 'circle'],
                        ['href' => $sortSignedUrl, 'label' => __('list.sort_signed'), 'icon' => $sort === 'signed' ? 'check' : 'circle'],
                    ]" />
                @endif

                @php
                    $categoryLinks = collect($categories ?? [])->map(fn ($cat) => [
                        'href' => lroute('petitions.byCategory', ['categorySlug' => $cat->tr_slug, 'category' => $cat->id]),
                        'label' => $cat->tr_name,
                        'active' => isset($category) && (int) $category->id === (int) $cat->id,
                    ])->values();
                @endphp

                @if ($categoryLinks->isNotEmpty())
                    <div class="hidden md:block mb-4">
                        <x-ui.chip-row>
                            <a href="{{ lroute('petitions.index') }}"
                               class="fcui-chip {{ isset($category) ? '' : 'is-active' }}"
                               @if (! isset($category)) aria-current="true" @endif>{{ __('list.all_petitions') }}</a>
                            @foreach ($categoryLinks as $chip)
                                <a href="{{ $chip['href'] }}" class="fcui-chip {{ $chip['active'] ? 'is-active' : '' }}"
                                   @if ($chip['active']) aria-current="true" @endif>{{ $chip['label'] }}</a>
                            @endforeach
                        </x-ui.chip-row>
                    </div>

                    <div class="flex gap-2 mb-4 md:hidden">
                        @if (! $search && isset($sort))
                            <button type="button" class="fcui-btn fcui-btn--secondary fcui-btn--sm max-md:min-h-12 flex-1 min-w-0" data-sheet-open="sort-sheet" aria-haspopup="dialog">
                                <x-lucide-arrow-down-up aria-hidden="true" />
                                <span class="truncate">{{ ['signed' => __('list.sort_signed'), 'trending' => __('list.sort_trending')][$sort] ?? __('list.sort_newest') }}</span>
                                <x-lucide-chevron-down class="size-3.5 shrink-0" aria-hidden="true" />
                            </button>
                        @endif
                        @if ($categoryLinks->isNotEmpty())
                            <button type="button" class="fcui-btn fcui-btn--secondary fcui-btn--sm max-md:min-h-12 flex-1 min-w-0" data-sheet-open="category-sheet" aria-haspopup="dialog">
                                <x-lucide-list aria-hidden="true" />
                                <span class="truncate">{{ isset($categoryTr) ? $categoryTr->name : __('list.categories') }}</span>
                                <x-lucide-chevron-down class="size-3.5 shrink-0" aria-hidden="true" />
                            </button>
                        @endif
                    </div>

                    <x-ui.action-sheet id="category-sheet" :title="__('list.all_petitions')" :actions="$categoryLinks->map(fn ($chip) => [
                        'href' => $chip['href'],
                        'label' => $chip['label'],
                        'icon' => $chip['active'] ? 'check' : 'circle',
                    ])->all()" />
                @endif

                <div data-skeleton hidden aria-hidden="true" class="flex flex-col gap-2">
                    <x-ui.skeleton variant="title" />
                    <x-ui.skeleton :lines="4" />
                </div>

                <div class="flex flex-col gap-2" data-results>
                    @forelse($petitions as $petition)
                        <a href="{{ lroute('petition.show', [
                            'slug' => $petition->tr_slug,
                            'id' => $petition->id,
                        ]) }}" data-prefetch style="view-transition-name: petition-{{ $petition->id }}" class="group flex justify-between items-center max-md:min-h-12 px-3.5 py-2.5 border border-border rounded-md text-brand text-sm bg-white transition-all duration-200 ease-out hover:bg-brand hover:text-white hover:border-brand hover:shadow-elev-2 hover:-translate-y-px hover:no-underline">
                            <span class="break-words min-w-0">
                                @php
                                    $titleHtml = e($petition->tr_title);
                                    if (is_string($search) && $search !== '') {
                                        // Split the raw title, then escape each piece, so a match can never land inside an entity.
                                        $parts = preg_split('/('.preg_quote($search, '/').')/iu', (string) $petition->tr_title, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                                        $titleHtml = $parts ? collect($parts)->map(fn ($part, $i) => $i % 2 ? '<mark>'.e($part).'</mark>' : e($part))->implode('') : $titleHtml;
                                    }
                                @endphp
                                {!! $titleHtml !!}
                            </span>
                            <span class="fc-tabular text-muted group-hover:text-white ms-4 max-md:ms-2 max-md:shrink-0 text-nowrap text-sm">
                                <span data-abbr data-abbr-value="{{ (int) ($petition->signature_count ?? 0) }}">{{ number_format($petition->signature_count ?? 0) }}</span> {{ __('list.signatures') }}
                            </span>
                        </a>
                    @empty
                        @php
                            $isSearchEmpty = is_string($search) && $search !== '';
                            $clearSearchUrl = request()->fullUrlWithQuery(['q' => null, 'page' => null]);
                            $emptyText = $isSearchEmpty
                                ? __('list.empty_search')
                                : (isset($category) ? __('list.empty_category') : __('list.empty_all'));
                        @endphp

                        <x-ui.empty :title="__('list.none')" :text="$emptyText">
                            <x-slot:icon>
                                @if ($isSearchEmpty)
                                    <svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                                        <circle cx="60" cy="60" r="56" fill="#fff5f5"></circle>
                                        <circle cx="60" cy="60" r="56" stroke="#f6d4d4" stroke-width="2"></circle>
                                        <rect x="38" y="30" width="44" height="56" rx="4" fill="#ffffff" stroke="#cc1f1f" stroke-width="2.5"></rect>
                                        <path d="M66 30v12h12" fill="#fff5f5" stroke="#cc1f1f" stroke-width="2.5" stroke-linejoin="round"></path>
                                        <line x1="46" y1="52" x2="74" y2="52" stroke="#f6a5a5" stroke-width="2.5" stroke-linecap="round"></line>
                                        <line x1="46" y1="62" x2="74" y2="62" stroke="#f6a5a5" stroke-width="2.5" stroke-linecap="round"></line>
                                        <line x1="46" y1="72" x2="62" y2="72" stroke="#f6a5a5" stroke-width="2.5" stroke-linecap="round"></line>
                                        <circle cx="80" cy="82" r="16" fill="#cc1f1f"></circle>
                                        <path d="M74 82h12M80 76v12" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"></path>
                                    </svg>
                                @endif
                            </x-slot:icon>

                            <x-slot:action>
                                <div class="flex flex-wrap items-center justify-center gap-2">
                                    @if ($isSearchEmpty)
                                        <x-ui.button :href="$clearSearchUrl" class="max-md:min-h-12">{{ __('list.clear') }}</x-ui.button>
                                    @elseif (isset($category))
                                        <x-ui.button :href="lroute('petitions.index')" class="max-md:min-h-12">{{ __('home.btn_browse_petitions') }}</x-ui.button>
                                    @endif
                                    <x-ui.button variant="ghost" :href="lroute('petition.create')" class="max-md:min-h-12">
                                        {{ __('home.btn_create_petition') }}
                                    </x-ui.button>
                                </div>
                            </x-slot:action>
                        </x-ui.empty>
                    @endforelse
                </div>

                <x-ui.pagination :paginator="$petitions" />

                {{-- Ads: banner468 (tablet+) --}}
                <x-ads.slot name="banner468" class="text-center mb-4 hidden md:block" />
            </div>

            {{-- ad: native, full width --}}
            <x-ads.slot name="native" class="mb-4" />

        </x-ui.container>
    </section>

    <button type="button" class="fcui-scrolltop" data-scroll-top aria-label="{{ __('ui.back_to_top') }}">↑</button>
@endsection
