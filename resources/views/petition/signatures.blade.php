@extends('layouts.legacy')

@php
    $petitionTitle = e($tr->title ?? __('common.petition'));
    $shortDesc = \Illuminate\Support\Str::limit(strip_tags($tr->description ?? ''), 200);
    $petitionUrl = lroute('petition.show', ['slug' => $tr->slug, 'id' => $petition->id]);
    $signUrl = lroute('petition.sign.page', ['slug' => $tr->slug, 'id' => $petition->id]);
    $coverImg = $petition->coverUrl();
@endphp

@section('title', __('sig.page_title', ['title' => $petitionTitle]))

@section('content')
    <x-ui.section>

            <div class="bg-white shadow-elev-1 rounded-md p-4 sm:p-6 mb-6">
                <div class="flex flex-wrap items-center -mx-3">

                    @php $wv = \App\Support\ImageVariants::urls($petition->cover_image); @endphp
                    <div class="w-full md:w-1/4 shrink-0 sm:w-1/3 px-3">
                        <a href="{{ $petitionUrl }}" data-prefetch class="rounded-lg overflow-hidden block h-40 bg-dark relative">
                            @if ($wv)
                            <picture>
                                <source type="image/webp" srcset="{{ $wv['400'] }} 400w, {{ $wv['800'] }} 800w" sizes="100vw">
                                <img src="{{ $coverImg }}" alt="" aria-hidden="true"
                                    class="absolute -inset-2.5 w-[calc(100%+20px)] h-[calc(100%+20px)] object-cover blur-[14px] opacity-[0.55]" loading="lazy" decoding="async">
                            </picture>
                            <picture>
                                <source type="image/webp" srcset="{{ $wv['400'] }} 400w, {{ $wv['800'] }} 800w" sizes="(min-width: 768px) 400px, 100vw">
                                <img src="{{ $coverImg }}" alt="{{ $petitionTitle }}" data-blurup
                                    class="fcui-blurup relative z-[1] w-full h-40 object-contain" loading="eager" decoding="async">
                            </picture>
                            @else
                            <img src="{{ $coverImg }}" alt="" aria-hidden="true"
                                class="absolute -inset-2.5 w-[calc(100%+20px)] h-[calc(100%+20px)] object-cover blur-[14px] opacity-[0.55]" loading="lazy" decoding="async">
                            <img src="{{ $coverImg }}" alt="{{ $petitionTitle }}" data-blurup
                                class="fcui-blurup relative z-[1] w-full h-40 object-contain" loading="eager" decoding="async">
                            @endif
                        </a>
                    </div>

                    <div class="w-full md:w-1/2 shrink-0 sm:w-2/3 px-3">
                        <h1 class="mb-2 text-2xl md:text-lg font-bold leading-[1.35]">
                            <a href="{{ $petitionUrl }}" data-prefetch class="text-ink no-underline">
                                {{ $petitionTitle }}
                            </a>
                        </h1>

                        <p class="text-muted mb-4 text-sm leading-[1.6]">
                            {{ $shortDesc }}
                            <a href="{{ $petitionUrl }}" data-prefetch class="text-brand ms-1">{{ __('sig.continue') }}</a>
                        </p>

                        <div class="fc-progress-reveal h-1.5 bg-surface-2 rounded-full overflow-hidden mb-2">
                            <div class="h-1.5 bg-success fcui-progress-fill" style="--pct: {{ $goalCurrent > 0 && $goalTotal > 0 ? max(round($goalCurrent / $goalTotal * 100, 2), 1.5) : 0 }}%"></div>
                        </div>
                        <div class="flex justify-between text-sm text-muted fc-tabular">
                            <span><strong>{{ number_format($goalCurrent) }}</strong> {{ __('sig.signatures') }}</span>
                            <span>{{ __('sig.goal') }} {{ number_format($goalTotal) }}</span>
                        </div>
                    </div>

                    <div class="w-full md:w-1/4 shrink-0 md:text-right px-3">
                        <x-ui.button variant="danger" :href="$signUrl" class="w-full max-md:min-h-12 md:w-auto">
                            {{ __('sig.sign_link') }}
                        </x-ui.button>
                    </div>

                </div>

            {{-- ad: native, full width --}}
            <x-ads.slot name="native" class="mb-4" />

            <div class="bg-white shadow-elev-1 rounded-md p-4 sm:p-6 fc-reveal">

                <div class="font-bold text-base mb-4">
                    {{ __('sig.all_signatures') }}
                    <span class="text-muted font-normal text-sm fc-tabular">
                        ({{ number_format($goalCurrent) }} {{ __('sig.total') }})
                    </span>
                </div>

                <div class="h-0.5 bg-surface-2 relative mb-6">
                    <div
                        class="h-0.5 w-full bg-gradient-to-r from-dark to-brand absolute left-0 top-0">
                    </div>
                </div>

                {{-- ad: banner468, tablet+ --}}
                <x-ads.slot name="banner468" class="mb-4 text-center hidden md:block" />
                @forelse($signatures as $sig)
                    @if ($loop->index > 0 && $loop->index % 4 === 0)
                        <x-ads.infeed />
                    @endif
                    <div class="flex items-start gap-4 py-4 {{ !$loop->last ? 'border-b' : '' }}">
                        <div class="shrink-0 text-muted text-sm min-w-8 pt-0.5 fc-tabular">
                            {{ ($signatures->currentPage() - 1) * $signatures->perPage() + $loop->iteration }}.
                        </div>
                        <div class="grow">
                            <div class="flex items-center gap-2 flex-wrap">
                                @if ($sig->user_id)
                                    <a href="{{ lroute('user.profile', ['slug' => \Illuminate\Support\Str::slug($sig->user->name ?? 'user') ?: 'user', 'id' => $sig->user_id]) }}" class="text-sm fw-bold">{{ $sig->display_name ?? __('sig.anonymous') }}</a>
                                @else
                                    <strong class="text-sm">{{ $sig->display_name ?? __('sig.anonymous') }}</strong>
                                @endif
                                @if ($sig->user?->city)
                                    <span class="text-muted text-sm">
                                        · {{ $sig->user->city }}
                                    </span>
                                @endif
                                <span class="text-muted ms-auto text-xs fc-tabular">
                                    {{ optional($sig->created_at)->format('j F Y') }}
                                </span>
                            </div>
                            @if (!empty($sig->text) && $sig->text !== __('sig.default_comment'))
                                <p class="mb-0 mt-1 text-muted text-sm">
                                    "{{ $sig->text }}"
                                </p>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.empty :text="__('sig.no_signatures')">
                        <x-slot:icon>
                            <svg xmlns="http://www.w3.org/2000/svg" width="112" height="112" viewBox="0 0 120 120" fill="none" aria-hidden="true" class="mb-4">
                                <circle cx="60" cy="60" r="56" fill="#fff5f5"></circle>
                                <circle cx="60" cy="60" r="56" stroke="#f6d4d4" stroke-width="2"></circle>
                                <rect x="34" y="40" width="52" height="40" rx="6" fill="#ffffff" stroke="#cc1f1f" stroke-width="2.5"></rect>
                                <line x1="44" y1="54" x2="76" y2="54" stroke="#f6a5a5" stroke-width="2.5" stroke-linecap="round"></line>
                                <line x1="44" y1="64" x2="64" y2="64" stroke="#f6a5a5" stroke-width="2.5" stroke-linecap="round"></line>
                                <path d="M70 72l5 5 11-11" stroke="#cc1f1f" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"></path>
                            </svg>
                        </x-slot:icon>
                    </x-ui.empty>
                @endforelse

                @include('partials.pagination', ['paginator' => $signatures])

            </div>

        </x-ui.section>

    <button type="button" class="fcui-scrolltop" data-scroll-top aria-label="{{ __('ui.back_to_top') }}">↑</button>
@endsection
