@extends('layouts.legacy')
@push('preload')
    <link rel="preload" href="{{ asset('fonts/Inter/InterVariable-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@endpush
@section('title', __('list.petitions').' | FreeCause')
@section('content')
<div class="fc-home">
<section class="bg-white border-b border-border py-12 md:py-16" aria-labelledby="home-hero-title">
    <x-ui.container>
        <div class="max-w-3xl mx-auto text-center">
            <h1 id="home-hero-title" class="text-3xl md:text-5xl leading-tight font-bold text-ink mb-6 break-words">{{ $directoryReady ? __('home.hero.title', ['country'=>$countryName]) : __('home.hero.title_petitions') }}</h1>
            <p class="text-muted text-lg max-w-2xl mx-auto mb-7">{{ $directoryReady ? __('home.hero.subtitle') : __('home.hero.subtitle_petitions') }}</p>
            <form method="GET" action="{{ lroute('search') }}" role="search" class="flex flex-col sm:flex-row gap-0 max-w-2xl mx-auto">
                <label class="sr-only" for="home-search">{{ __('home.hero.search_label') }}</label>
                <div class="relative min-w-0 flex-1"><x-lucide-search class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted" aria-hidden="true" /><input id="home-search" name="q" type="search" maxlength="120" placeholder="{{ __('home.hero.search') }}" class="fcui-input w-full pl-10 sm:rounded-r-none" required></div>
                <x-ui.button class="sm:rounded-l-none">{{ __('home.search_button') }}</x-ui.button>
            </form>
            <div class="flex flex-wrap gap-3 justify-center mt-6">
                @if($directoryReady)<x-ui.button :href="lroute('organizations.claim-search')">{{ __('organizations.claim_organization') }}</x-ui.button>@endif
                <x-ui.button :href="lroute('petition.create')" :variant="$directoryReady ? 'ghost' : 'primary'" :class="$directoryReady ? 'border-border text-ink' : ''">{{ __('home.start_petition') }}</x-ui.button>
            </div>
            @if($organizations_count > 0)<p class="text-sm text-muted mt-5">{{ __('home.hero.proof', ['signatures'=>number_format($total_signatures), 'organizations'=>number_format($organizations_count)]) }}</p>@endif
        </div>
    </x-ui.container>
</section>

<section class="py-10 md:py-12" data-home-organizations aria-labelledby="home-organizations-title">
    <x-ui.container>
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3 mb-6">
            <div><h2 id="home-organizations-title" class="text-2xl font-bold mb-2">{{ $organizationsTrending ? __('home.trending_title', ['country'=>$countryName]) : __('home.active_title') }}</h2>
            @if($organizationsTrending)<p class="text-sm text-muted">{{ __('home.trending_subtitle') }}</p>@endif</div>
            <a class="text-brand text-sm whitespace-nowrap hover:underline" href="{{ lroute('organizations.explore') }}">{{ __('organizations.see_all') }} →</a>
        </div>
        <ul class="grid md:grid-cols-3 gap-5">
            @forelse($organizations as $org)
            @php $main = $org->mainCampaign?->translationOrFallback(app()->getLocale(), 'en'); @endphp
            <li class="bg-white border border-border rounded-lg shadow-elev-1 p-4 min-w-0">
                <a href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}" class="flex items-center gap-3 text-ink hover:text-brand">
                    @if($org->status === 'verified' && $org->logo_path)<img src="{{ Storage::disk('public')->url($org->logo_path) }}" width="48" height="48" loading="lazy" class="size-12 rounded-sm object-cover shrink-0" alt="">@else<span class="size-12 flex items-center justify-center {{ hexdec(substr(hash('sha256', $org->slug), 0, 2)) % 2 ? 'bg-brand-soft' : 'bg-surface-muted' }} rounded-sm shrink-0 font-bold" aria-hidden="true">{{ mb_substr($org->name, 0, 1) }}</span>@endif
                    <div class="min-w-0"><h3 class="font-semibold text-base leading-snug break-words">{{ $org->name }}</h3>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 mt-1 text-xs {{ $org->status === 'verified' ? 'bg-success-soft text-ink' : 'bg-warning-soft text-warning' }}">@if($org->status === 'verified')<x-lucide-check class="size-3" aria-hidden="true" />@endif{{ $org->status === 'verified' ? __('home.status_verified') : __('home.status_unclaimed') }}</span></div>
                </a>
                <div class="flex flex-wrap gap-2 text-xs mt-3">@if($org->causes->first())<span class="bg-band text-ink rounded-sm px-2 py-1 max-w-full break-words">{{ $org->causes->first()->label() }}</span>@endif<span class="bg-band text-ink rounded-sm px-2 py-1 max-w-full break-words">{{ $org->territory ?: $org->country }}</span></div>
                @if($main)<p class="text-sm text-muted mt-3 break-words">{{ __('home.main_campaign', ['title'=>$main->title]) }}</p>@endif
                <p class="text-brand text-sm font-semibold mt-3">{{ __('home.weekly_signatures', ['count'=>number_format($org->signatures_7d_cache)]) }}</p>
            </li>
            @empty
            <li class="text-sm text-muted md:col-span-3">{{ __('home.organizations_empty') }}</li>
            @endforelse
        </ul>
    </x-ui.container>
</section>

<section class="py-10 md:py-12" data-home-campaigns aria-labelledby="home-campaigns-title">
    <x-ui.container>
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3 mb-6">
            <div><h2 id="home-campaigns-title" class="text-2xl font-bold mb-2">{{ __('home.recently_signed_title') }}</h2>
            <p class="text-sm text-muted">{{ __('home.recently_signed_subtitle') }}</p></div>
            <a class="text-brand text-sm whitespace-nowrap hover:underline" href="{{ lroute('petitions.index') }}">{{ __('nav.explore') }} →</a>
        </div>
        <div class="grid md:grid-cols-3 gap-5">@forelse($campaigns as $petition)@include('home.campaign-card', ['petition'=>$petition])@empty<p class="text-sm text-muted md:col-span-3">{{ __('home.campaigns_empty') }}</p>@endforelse</div>
    </x-ui.container>
</section>

<section class="bg-white border-y border-border py-10 md:py-12" data-home-victories aria-labelledby="home-victories-title">
    <x-ui.container>
        <h2 id="home-victories-title" class="text-2xl font-bold mb-2">{{ __('home.victories_title') }}</h2>
        <p class="text-sm text-muted mb-6">{{ __('home.victories_subtitle') }}</p>
        <div class="grid md:grid-cols-3 gap-5">
            @forelse($victories as $petition)
            <article class="bg-white border border-border rounded-lg shadow-elev-1 p-4 min-w-0">
                <span class="bg-success-soft text-ink rounded-full px-2 py-1 text-xs">{{ __('home.victory_badge') }}</span>
                <h3 class="text-base font-semibold break-words my-3"><a class="text-ink hover:text-brand" href="{{ lroute('petition.show', ['slug'=>$petition->tr_slug, 'id'=>$petition->id]) }}">{{ $petition->tr_title }}</a></h3>
                <p class="text-sm text-muted break-words">{{ \Illuminate\Support\Str::limit(strip_tags($petition->outcome_summary ?? ''), 140) }}</p>
                <div class="mt-3">@include('home.author', ['petition'=>$petition, 'compact'=>true, 'date'=>$petition->outcome_at->locale(app()->getLocale())->translatedFormat('F Y')])</div>
            </article>
            @empty
            <p class="text-sm text-muted md:col-span-3">{{ __('home.victories_empty') }}</p>
            @endforelse
        </div>
    </x-ui.container>
</section>

@if($directoryReady && $causes->isNotEmpty())
<section class="py-10 md:pt-12 md:pb-8" data-home-causes aria-labelledby="home-causes-title">
    <x-ui.container>
        <h2 id="home-causes-title" class="text-2xl font-bold mb-5">{{ __('home.causes_title') }}</h2>
        <ul class="flex flex-wrap gap-2">@foreach($causes as $cause)<li><a class="inline-flex gap-2 bg-white border border-border text-ink rounded-full px-3 py-2 text-sm hover:underline" href="{{ lroute('organizations.explore', ['cause'=>$cause->slug]) }}">{{ $cause->label() }} <span class="text-muted">{{ number_format($cause->organization_count) }}</span></a></li>@endforeach</ul>
    </x-ui.container>
</section>
@endif

@if($directoryReady && $unclaimed_count > 0)
<section class="pb-10 md:pb-12" data-home-claim aria-labelledby="home-claim-title">
    <x-ui.container>
        <div class="bg-dark text-on-dark rounded-lg p-6 md:p-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="max-w-2xl"><h2 id="home-claim-title" class="text-2xl font-bold leading-snug mb-3">{{ __('home.claim_title', ['count'=>number_format($unclaimed_count)]) }}</h2><p class="text-sm text-on-dark">{{ __('home.claim_subtitle') }}</p></div>
            <x-ui.button class="shrink-0 whitespace-nowrap self-start md:self-auto" :href="lroute('organizations.claim-search')">{{ __('organizations.find_organization') }}</x-ui.button>
        </div>
    </x-ui.container>
</section>
@endif

<section class="bg-white border-y border-border py-10 md:py-12" aria-labelledby="home-how-title">
    <x-ui.container>
        <h2 id="home-how-title" class="text-2xl font-bold mb-6">{{ __('home.how_title') }}</h2>
        <ol class="grid md:grid-cols-3 gap-7">
            @foreach([1,2,3] as $step)<li class="min-w-0 flex items-start gap-3"><span class="size-8 bg-band text-muted rounded-lg shrink-0 flex items-center justify-center font-semibold" aria-hidden="true">{{ $step }}</span><div><h3 class="text-base font-semibold">{{ __('home.step_'.$step.($step === 1 && !$directoryReady ? '_petitions' : '')) }}</h3><p class="text-sm text-muted mt-1">{{ __('home.step_'.$step.($step === 1 && !$directoryReady ? '_petitions_sub' : '_sub')) }}</p></div></li>@endforeach
        </ol>
        <ul class="flex flex-wrap justify-center items-center gap-x-5 gap-y-3 text-sm text-muted mt-10">
            <li>{{ __('home.since') }}</li>@if($verified_count > 0)<li>{{ __('home.verified_count', ['count'=>number_format($verified_count)]) }}</li>@endif
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'ethical-code']) }}">{{ __('pages.ethical_code') }}</a></li>
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'terms-of-service']) }}">{{ __('home.moderation') }}</a></li>
            <li><a class="underline" href="{{ lroute('page.show', ['slug'=>'privacy-policy']) }}">{{ __('pages.privacy_policy') }}</a></li>
        </ul>
    </x-ui.container>
</section>

    @if (count($magazinePosts) >= 3)
    <section class="bg-band py-10 md:py-12">
        <x-ui.container>
            <div class="text-center mb-6">
                <h2 class="text-2xl font-bold mb-2">{{ __('home.blog_title') }}</h2>
                <p class="text-sm text-muted">{{ __('home.blog_subtitle') }}
                </p>
            </div>

            <div class="flex flex-wrap -mx-3 fc-reveal fc-stagger">
                @foreach ($magazinePosts as $post)
                    <div class="w-full md:w-1/3 shrink-0 grow-0 px-3 mb-6" style="--fc-i: {{ min($loop->index, 12) }}">
                        <div class="shadow-elev-1 rounded-lg overflow-hidden bg-white h-full">
                        <a class="block no-underline" href="/magazine/{{ $post->post_name }}">
                                <div>
                                    @if ($post->thumbnail_path)
                                        <img src="/magazine/wp-content/uploads/{{ $post->thumbnail_path }}" alt="{{ $post->post_title }}" loading="lazy" decoding="async" data-blurup class="fcui-blurup w-full aspect-[16/9] object-cover">
                                    @else
                                        <img src="/magazine/wp-content/themes/freecause/assets/images/default-post.jpg" alt="{{ $post->post_title }}" loading="lazy" decoding="async" data-blurup class="fcui-blurup w-full aspect-[16/9] object-cover">
                                    @endif
                                </div>
                                <div class="p-5 bg-white min-h-36">
                                    <h3 class="text-base font-semibold mb-2.5 text-ink break-words">{{ $post->post_title }}</h3>
                                    <span class="text-brand">
                                    {{ __('home.blog_read_more') }}
                                    <span class="ml-1.5">›</span>
                                    </span>
                                </div>
                        </a>
                        @if($post->related_campaign ?? null)
                            <p class="text-xs px-5 pb-5 text-muted"><a class="text-brand hover:underline" href="{{ lroute('petition.show', ['slug'=>$post->related_campaign->slug, 'id'=>$post->related_campaign->petition_id]) }}">{{ __('home.related_campaign', ['title'=>$post->related_campaign->title]) }}</a></p>
                        @endif
                        </div>
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
</div>
@endsection
@push('head')
    @include('partials.hreflang')
@endpush
