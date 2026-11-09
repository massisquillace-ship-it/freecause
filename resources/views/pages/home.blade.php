@extends('layouts.legacy')
@push('preload')
    <link rel="preload" href="{{ asset('fonts/Inter/InterVariable-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@endpush
@section('title', __('list.petitions').' | FreeCause')
@section('content')
<section class="bg-white border-b border-border fc-section" aria-labelledby="home-hero-title">
    <x-ui.container>
        <div class="max-w-4xl mx-auto text-center">
            <h1 id="home-hero-title" class="text-3xl md:text-5xl font-semibold text-ink mb-5 break-words">{{ $directoryReady ? __('home.hero.title', ['country'=>$countryName]) : __('home.hero.title_petitions') }}</h1>
            <p class="text-muted text-lg md:text-xl mb-7">{{ $directoryReady ? __('home.hero.subtitle') : __('home.hero.subtitle_petitions') }}</p>
            <form method="GET" action="{{ lroute('search') }}" role="search" class="flex flex-col sm:flex-row gap-2 max-w-2xl mx-auto">
                <label class="sr-only" for="home-search">{{ __('home.hero.search_label') }}</label>
                <input id="home-search" name="q" type="search" maxlength="120" placeholder="{{ __('home.hero.search') }}" class="fcui-input min-w-0 flex-1" required>
                <x-ui.button>{{ __('home.search_button') }}</x-ui.button>
            </form>
            <div class="flex flex-wrap gap-3 justify-center mt-6">
                @if($directoryReady)<x-ui.button :href="lroute('organizations.claim-search')">{{ __('organizations.claim_organization') }}</x-ui.button>@endif
                <x-ui.button :href="lroute('petition.create')" :variant="$directoryReady ? 'ghost' : 'primary'">{{ __('home.start_petition') }}</x-ui.button>
            </div>
            @if($organizations_count >= 50)<p class="text-sm text-muted mt-5">{{ __('home.hero.proof', ['signatures'=>number_format($total_signatures), 'organizations'=>number_format($organizations_count)]) }}</p>@endif
        </div>
    </x-ui.container>
</section>

@if($directoryReady && $organizations->count() >= 3)
<section class="fc-section" data-home-organizations aria-labelledby="home-organizations-title">
    <x-ui.container>
        <h2 id="home-organizations-title" class="text-2xl font-semibold mb-2">{{ $organizationsTrending ? __('home.trending_title', ['country'=>$countryName]) : __('home.active_title') }}</h2>
        @if($organizationsTrending)<p class="text-muted mb-6">{{ __('home.trending_subtitle') }}</p>@endif
        <ul class="grid md:grid-cols-3 gap-6 mt-6">
            @foreach($organizations as $org)
            @php $main = $org->mainCampaign?->translationOrFallback(app()->getLocale(), 'en'); @endphp
            <li class="bg-white rounded-lg shadow-elev-1 p-5 min-w-0">
                <a href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}" class="flex items-center gap-3 text-ink hover:text-brand">
                    @if($org->status === 'verified' && $org->logo_path)<img src="{{ Storage::disk('public')->url($org->logo_path) }}" width="48" height="48" loading="lazy" class="size-12 rounded-sm object-cover shrink-0" alt="">@else<span class="size-12 flex items-center justify-center {{ hexdec(substr(hash('sha256', $org->slug), 0, 2)) % 2 ? 'bg-brand-soft' : 'bg-surface-muted' }} rounded-sm shrink-0 font-bold" aria-hidden="true">{{ mb_substr($org->name, 0, 1) }}</span>@endif
                    <h3 class="font-semibold text-lg break-words">{{ $org->name }}</h3>
                </a>
                <span class="inline-block rounded-sm px-2 py-1 mt-3 text-xs {{ $org->status === 'verified' ? 'bg-success-soft text-ink' : 'bg-warning-soft text-warning' }}">{{ $org->status === 'verified' ? __('organizations.verified') : __('organizations.unclaimed') }}</span>
                <div class="flex flex-wrap gap-2 text-xs mt-3">@if($org->causes->first())<span class="bg-band text-ink rounded-sm px-2 py-1 max-w-full break-words">{{ $org->causes->first()->label() }}</span>@endif<span class="bg-band text-ink rounded-sm px-2 py-1 max-w-full break-words">{{ $org->territory ?: $org->country }}</span></div>
                @if($main)<p class="text-sm mt-4 break-words">{{ __('home.main_campaign', ['title'=>$main->title]) }}</p>@endif
                <p class="text-brand font-semibold mt-3">{{ __('home.weekly_signatures', ['count'=>number_format($org->signatures_7d_cache)]) }}</p>
            </li>
            @endforeach
        </ul>
        <a class="text-brand underline inline-block mt-5" href="{{ lroute('organizations.explore') }}">{{ __('organizations.see_all') }} →</a>
    </x-ui.container>
</section>
@endif

@if($campaigns->count() >= 3)
<section class="bg-band fc-section" data-home-campaigns aria-labelledby="home-campaigns-title">
    <x-ui.container>
        <h2 id="home-campaigns-title" class="text-2xl font-semibold mb-2">{{ $campaignsTrending ? __('home.momentum_title') : __('home.popular_title') }}</h2>
        <p class="text-muted mb-6">{{ $campaignsTrending ? __('home.momentum_subtitle') : __('home.popular_subtitle') }}</p>
        <div class="grid md:grid-cols-3 gap-6">@foreach($campaigns as $petition)@include('home.campaign-card', ['petition'=>$petition])@endforeach</div>
    </x-ui.container>
</section>
@endif

@if($victories->isNotEmpty())
<section class="bg-white fc-section" data-home-victories aria-labelledby="home-victories-title">
    <x-ui.container>
        <h2 id="home-victories-title" class="text-2xl font-semibold mb-2">{{ __('home.victories_title') }}</h2>
        <p class="text-muted mb-6">{{ __('home.victories_subtitle') }}</p>
        <div class="grid md:grid-cols-3 gap-6">
            @foreach($victories as $petition)
            <article class="bg-white border border-border rounded-lg p-5 min-w-0">
                <span class="bg-success-soft text-ink rounded-sm px-2 py-1 text-xs">{{ __('home.victories_title') }}</span>
                <h3 class="text-lg font-semibold break-words my-3"><a href="{{ lroute('petition.show', ['slug'=>$petition->tr_slug, 'id'=>$petition->id]) }}">{{ $petition->tr_title }}</a></h3>
                <p class="text-muted break-words">{{ \Illuminate\Support\Str::limit(strip_tags($petition->outcome_summary ?? ''), 140) }}</p>
                @include('home.author', ['petition'=>$petition])
                <p class="text-sm text-muted mt-2">{{ $petition->outcome_at->locale(app()->getLocale())->translatedFormat('F Y') }}</p>
            </article>
            @endforeach
        </div>
    </x-ui.container>
</section>
@endif

@if($directoryReady && $causes->isNotEmpty())
<section class="fc-section" data-home-causes aria-labelledby="home-causes-title">
    <x-ui.container>
        <h2 id="home-causes-title" class="text-2xl font-semibold mb-6">{{ __('home.causes_title') }}</h2>
        <ul class="flex flex-wrap gap-3">@foreach($causes as $cause)<li><a class="inline-flex gap-2 bg-band text-ink rounded-lg px-4 py-3 hover:underline" href="{{ lroute('organizations.explore', ['cause'=>$cause->slug]) }}">{{ $cause->label() }} <span>{{ number_format($cause->organization_count) }}</span></a></li>@endforeach</ul>
    </x-ui.container>
</section>
@endif

@if($directoryReady && $unclaimed_count >= 20)
<section class="bg-dark text-on-dark fc-section" data-home-claim aria-labelledby="home-claim-title">
    <x-ui.container>
        <div class="max-w-3xl mx-auto text-center"><h2 id="home-claim-title" class="text-2xl font-semibold mb-3">{{ __('home.claim_title', ['count'=>number_format($unclaimed_count)]) }}</h2><p class="mb-6 text-on-dark">{{ __('home.claim_subtitle') }}</p><x-ui.button :href="lroute('organizations.claim-search')">{{ __('organizations.find_organization') }}</x-ui.button></div>
    </x-ui.container>
</section>
@endif

<section class="bg-white fc-section" aria-labelledby="home-how-title">
    <x-ui.container>
        <h2 id="home-how-title" class="text-2xl font-semibold text-center mb-7">{{ __('home.how_title') }}</h2>
        <ol class="grid md:grid-cols-3 gap-7">
            @foreach([1,2,3] as $step)<li class="min-w-0"><span class="text-brand font-semibold text-2xl" aria-hidden="true">{{ $step }}.</span><h3 class="text-lg font-semibold mt-2">{{ __('home.step_'.$step.($step === 1 && !$directoryReady ? '_petitions' : '')) }}</h3><p class="text-muted mt-2">{{ __('home.step_'.$step.($step === 1 && !$directoryReady ? '_petitions_sub' : '_sub')) }}</p></li>@endforeach
        </ol>
        <ul class="flex flex-wrap justify-center items-center gap-x-5 gap-y-3 text-sm text-muted mt-10">
            <li>{{ __('home.since') }}</li>@if($verified_count >= 10)<li>{{ __('home.verified_count', ['count'=>number_format($verified_count)]) }}</li>@endif
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'ethical-code']) }}">{{ __('pages.ethical_code') }}</a></li>
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'terms-of-service']) }}">{{ __('home.moderation') }}</a></li>
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'privacy-policy']) }}">{{ __('pages.privacy_policy') }}</a></li>
        </ul>
    </x-ui.container>
</section>

    @if (count($magazinePosts) >= 3)
    <section class="bg-band fc-section">
        <x-ui.container>
            <div class="text-center mb-6">
                <h2 class="mb-2">{{ __('home.blog_title') }}</h2>
                <p class="text-muted">{{ __('home.blog_subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap -mx-3 fc-reveal fc-stagger">
                @foreach ($magazinePosts as $post)
                    <div class="w-full md:w-1/3 shrink-0 grow-0 px-3 mb-6" style="--fc-i: {{ min($loop->index, 12) }}">
                        <a class="block h-full no-underline" href="/magazine/{{ $post->post_name }}">
                            <div class="shadow-elev-1 rounded-lg overflow-hidden">
                                <div>
                                    @if ($post->thumbnail_path)
                                        <img src="/magazine/wp-content/uploads/{{ $post->thumbnail_path }}" alt="{{ $post->post_title }}" loading="lazy" decoding="async" data-blurup class="fcui-blurup w-full aspect-[16/9] object-cover">
                                    @else
                                        <img src="/magazine/wp-content/themes/freecause/assets/images/default-post.jpg" alt="{{ $post->post_title }}" loading="lazy" decoding="async" data-blurup class="fcui-blurup w-full aspect-[16/9] object-cover">
                                    @endif
                                </div>
                                <div class="p-5 bg-white min-h-36">
                                    <h3 class="text-base md:text-base mb-2.5 text-ink break-words">{{ $post->post_title }}</h3>
                                    <span class="text-brand">
                                    {{ __('home.blog_read_more') }}
                                    <span class="ml-1.5">›</span>
                                    </span>
                                </div>
                            </div>
                        </a>
                        @if($post->related_campaign ?? null)
                            <p class="text-sm mt-2"><a class="text-brand underline" href="{{ lroute('petition.show', ['slug'=>$post->related_campaign->slug, 'id'=>$post->related_campaign->petition_id]) }}">{{ __('home.related_campaign', ['title'=>$post->related_campaign->title]) }}</a></p>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="text-center mt-6">
                <x-ui.button variant="ghost" href="/magazine">
                    {{ __('home.blog_view_all') }}
                </x-ui.button>
            </div>
        </x-ui.container>
    </section>
    @endif

    <x-ads.slot name="native" class="my-6" />
@endsection
@push('head')
    @include('partials.hreflang')
@endpush
