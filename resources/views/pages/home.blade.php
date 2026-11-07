@extends('layouts.legacy')

@section('title', __('list.petitions').' | FreeCause')

@php
    $featuredUrl = $featuredPetition
        ? lroute('petition.show', ['slug' => $featuredPetition->tr_slug, 'id' => $featuredPetition->id])
        : null;

    $signatures = $featuredPetition?->signature_count ?? 0;
    $goal = $featuredPetition?->goal_signatures ?? 100;
    $progress = $goal > 0 ? min(100, ($signatures / $goal) * 100) : 0;
@endphp

@section('content')

    <section id="welcomes" class="bg-band fc-section fc-hero max-md:py-8">
        <x-ui.container>
            <div class="flex flex-wrap -mx-3 items-center justify-center">
                <div class="w-full md:w-2/3 shrink-0 grow-0 px-3 text-center">

                    <h1 class="fc-hero-item text-ink font-semibold text-2xl md:text-5xl mb-6 max-sm:mb-4">
                        {{ $content['h1'] ?? __('home.h1') }}
                    </h1>

                    <h2 class="fc-hero-item text-muted text-base leading-normal md:text-xl">
                        {!! \App\Services\ContentRenderer::render($content['h2'] ?? __('home.h2')) !!}
                    </h2>

                    <div class="fc-hero-item mt-7 mb-4 max-md:mt-5">
                        <x-ui.button :href="lroute('petition.create')" variant="primary" size="lg">
                            {{ __('home.btn_create_petition') }}
                        </x-ui.button>
                    </div>

                </div>
            </div>
        </x-ui.container>
    </section>


    <section class="fc-section">
        <x-ui.container>
            <div class="flex flex-wrap -mx-3">
                <div class="w-full shrink-0 grow-0 px-3">
                    <div class="fcui-card">

                        <ul class="nav nav-tabs" id="myTab">
                            <li class="nav-item">
                                <button class="nav-link active max-md:min-h-12" data-bs-toggle="tab"
                                    data-bs-target="#featured-petition-tab-pane">
                                    <svg class="inline-block mr-1.5" xmlns="http://www.w3.org/2000/svg" width="1.2em" height="1.2em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.5 2.6 10.6h1.3v10.8h5.3v-5.8h5.6v5.8h5.3V10.6h1.3L12 2.5Z"/></svg>
                                    {{ __('home.tab_featured') }}
                                </button>
                            </li>

                            <li class="nav-item">
                                <button class="nav-link max-md:min-h-12" data-bs-toggle="tab" data-bs-target="#recent-activities-tab-pane">
                                    <svg class="inline-block mr-1.5" xmlns="http://www.w3.org/2000/svg" width="1.2em" height="1.2em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><polyline points="12 7 12 12 15.5 13.5"/></svg>
                                    {{ __('home.tab_recent') }}
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">

                            <div class="tab-pane fade show active" id="featured-petition-tab-pane">

                                <div class="p-4 md:p-6 max-sm:p-3">
                                    <div class="flex flex-wrap -mx-3">
                                        <div class="w-full shrink-0 grow-0 px-3 mb-6 max-md:mb-8">

                                            <div class="featured-box relative flex flex-col min-w-0 bg-white bg-clip-border rounded-md fc-lift">
                                                <div class="grow shrink basis-auto p-5 max-sm:p-4">

                                                    <span class="bg-brand/5 text-danger px-5 py-1 rounded-sm text-sm mb-4 inline-block">
                                                        {{ __('home.featured_badge') }}
                                                    </span>

                                                    <div class="flex flex-wrap -mx-3 relative">

                                                        @if ($featuredPetition)
                                                        <div class="w-full md:w-1/2 shrink-0 grow-0 px-3 mb-6 max-sm:mb-4">
                                                            @php $wv = \App\Support\ImageVariants::urls($featuredPetition->cover_image); @endphp
                                                            <div class="relative w-full aspect-[16/9] overflow-hidden rounded-md bg-surface-2">
                                                                <x-ui.skeleton class="absolute inset-0 h-full w-full" aria-hidden="true" />
                                                                @if ($wv)
                                                                <picture class="absolute inset-0">
                                                                    <source type="image/webp" srcset="{{ $wv['400'] }} 400w, {{ $wv['800'] }} 800w" sizes="(min-width: 768px) 50vw, 100vw">
                                                                    <img src="{{ $featuredPetition->coverUrl() }}" class="fcui-blurup fcui-cover-fade relative img-fluid w-full aspect-[16/9] object-cover" data-blurup
                                                                        loading="eager" decoding="async" alt="{{ $featuredPetition->tr_title }}">
                                                                </picture>
                                                                @else
                                                                <img src="{{ $featuredPetition->coverUrl() }}" class="fcui-blurup fcui-cover-fade relative img-fluid w-full aspect-[16/9] object-cover" data-blurup
                                                                    loading="eager" decoding="async" alt="{{ $featuredPetition->tr_title }}">
                                                                @endif
                                                            </div>
                                                        </div>

                                                        <div class="w-full md:w-1/2 shrink-0 grow-0 px-3 mb-6 max-sm:mb-4">
                                                                <h3 class="break-words">{{ $featuredPetition->tr_title }}</h3>
                                                                <p>
                                                                    <strong>
                                                                        {{ __('home.petition_target_label') }}
                                                                    </strong>
                                                                    {{ $featuredPetition->target ?? '-' }}
                                                                </p>

                                                                <p class="line-clamp-7">
                                                                    {{ \Illuminate\Support\Str::limit(strip_tags($featuredPetition->tr_description ?? ''), 600) }}
                                                                </p>

                                                            @else
                                                                <h3>{{ __('home.featured_none_title') }}</h3>
                                                                <p class="text-muted">
                                                                    {{ __('home.featured_none_sub') }}
                                                                </p>
                                                            @endif
                                                        </div>

                                                        @if ($featuredPetition)
                                                            <a href="{{ $featuredUrl }}" data-prefetch class="absolute inset-0 z-10 text-transparent bg-transparent">
                                                                {{ __('home.featured_read_more') }}
                                                            </a>
                                                        @endif
                                                    </div>

                                                    @if ($featuredPetition)
                                                        <div class="mb-4">
                                                            <div class="progress mb-2">
                                                                <div class="progress-bar fcui-progress-fill" style="--pct: {{ $progress > 0 ? max($progress, 1.5) : 0 }}%"
                                                                    role="progressbar" aria-label="{{ __('home.signatures_label') }}" aria-valuenow="{{ (int) $progress }}"
                                                                    aria-valuemin="0" aria-valuemax="100"></div>
                                                            </div>
                                                            <div class="flex justify-between text-muted">
                                                                <span class="fc-tabular"><span data-countup data-countup-to="{{ (int) $signatures }}">{{ number_format($signatures) }}</span> {{ __('home.signatures_label') }}</span>
                                                                <span class="fc-tabular">{{ __('home.goal_label') }} {{ number_format($goal) }}</span>
                                                            </div>
                                                        </div>

                                                        <x-ui.button :href="$featuredUrl" variant="primary" class="max-md:min-h-12">
                                                            {{ __('home.featured_read_more') }}
                                                        </x-ui.button>
                                                    @endif

                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </div>

                            </div>

                            <div class="tab-pane fade" id="recent-activities-tab-pane" role="tabpanel">

                                <div class="p-4 md:p-6 max-sm:p-3">
                                    <div class="flex flex-wrap -mx-3">
                                        <div class="w-full shrink-0 grow-0 px-3">
                                            <div class="mb-12">

                                                <ul class="most-listing">

                                                    @forelse($recentActivities as $sig)
                                                        @php
                                                            $hasPetition =
                                                                !empty($sig->petition_id) &&
                                                                !empty($sig->petition_slug);

                                                            $url = $hasPetition
                                                                ? lroute('petition.show', [
                                                                    'slug' => $sig->petition_slug,
                                                                    'id' => $sig->petition_id,
                                                                ])
                                                                : '#';
                                                        @endphp

                                                        <li class="group">
                                                            <a href="{{ $url }}" @if($hasPetition) data-prefetch @endif class="block max-md:min-h-12">

                                                                <div
                                                                    class="flex justify-between mb-1">
                                                                    <p class="text-sm break-words min-w-0">
                                                                        <span>
                                                                            {{ $sig->display_name ?? $sig->name ?? '' }}
                                                                        </span>

                                                                        <strong>
                                                                            {{ __('home.recent_has_signed') }}
                                                                        </strong>
                                                                    </p>

                                                                    <span class="text-brand text-sm group-hover:text-white">
                                                                        {{ $sig->created_at ? \Carbon\Carbon::parse($sig->created_at)->diffForHumans() : '' }}
                                                                    </span>
                                                                </div>

                                                                <p class="text-sm break-words">
                                                                    {{ $sig->petition_title }}
                                                                </p>

                                                            </a>
                                                        </li>

                                                    @empty

                                                        <li class="group">
                                                            <div class="text-muted p-2">
                                                                {{ __('home.recent_empty') }}
                                                                &mdash;
                                                                <a href="{{ lroute('petitions.index') }}" class="text-brand group-hover:text-white">
                                                                    {{ __('home.btn_browse_petitions') }}
                                                                </a>
                                                            </div>
                                                        </li>
                                                    @endforelse

                                                </ul>

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </x-ui.container>
    </section>

    <section class="fc-section bg-band">
        <x-ui.container>
            <div class="flex flex-wrap -mx-3">

                <div class="w-full md:w-1/2 shrink-0 grow-0 px-3 mb-6 max-md:mb-8">
                    <div class="gray-box bg-white p-4 rounded-lg shadow-elev-1">

                        <h2 class="fcui-section-title capitalize text-xl md:text-2xl">
                            {{ __('home.what_title') }}
                        </h2>

                        {!! \App\Services\ContentRenderer::render($content['text_index_left'] ?? __('home.text_index_left')) !!}

                    </div>
                </div>

                <div class="w-full md:w-1/2 shrink-0 grow-0 px-3">
                    <div class="gray-box bg-white p-4 rounded-lg shadow-elev-1">

                        <h2 class="fcui-section-title capitalize text-xl md:text-2xl">
                            {{ __('home.create_box_title') }}
                        </h2>

                        {!! \App\Services\ContentRenderer::render($content['text_index_right'] ?? __('home.text_index_right')) !!}

                    </div>
                </div>

            </div>
        </x-ui.container>
    </section>

    <section class="fc-section">
        <x-ui.container>
            <div class="flex flex-wrap -mx-3 mb-6">
                <div class="w-full shrink-0 grow-0 px-3">
                    <h2 class="fcui-section-title capitalize text-xl md:text-2xl">{{ __('home.categories_title') }}</h2>
                </div>
            </div>

            <x-ui.chip-row class="-mx-3 md:hidden">
                @foreach ($categories as $cat)
                    <a href="{{ lroute('petitions.byCategory', ['categorySlug' => $cat->tr_slug, 'category' => $cat->id]) }}"
                        class="fcui-chip max-md:min-h-12">{{ $cat->tr_name }}</a>
                @endforeach
            </x-ui.chip-row>

            <div class="hidden md:flex flex-wrap -mx-3 fc-reveal fc-stagger">
                @foreach ($categories as $cat)
                    <div class="w-full lg:w-1/4 sm:w-1/2 shrink-0 grow-0 px-3 mb-4" style="--fc-i: {{ min($loop->index, 12) }}">
                        <a href="{{ lroute('petitions.byCategory', ['categorySlug' => $cat->tr_slug, 'category' => $cat->id]) }}"
                            class="bg-white border border-border rounded-lg p-6 text-center no-underline text-ink block">
                            <h3 class="text-base md:text-base">{{ $cat->tr_name }}</h3>
                        </a>
                    </div>
                @endforeach
            </div>
        </x-ui.container>
    </section>

    @if (count($magazinePosts) > 0)
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

    {{-- Ads: native --}}
    <x-ads.slot name="native" class="my-6" />
    @include('organizations.home-cards')
@endsection

@push('head')
    @include('partials.hreflang')
@endpush
