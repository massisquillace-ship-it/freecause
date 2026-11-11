@extends('layouts.legacy')

@php
    $isAuthed = auth()->check();

    $petitionTitle = \App\Support\Seo::text($tr->title ?? ($petition->title ?? 'Petition'));
    $pageTitle = __('show.page_title', ['title' => $petitionTitle]);

    $petitionImg = $petition->coverUrl();
    $coverVariants = \App\Support\ImageVariants::urls($petition->cover_image);
    $youtubeId = \App\Models\Petition::youtubeId($petition->youtube_url);
    $hasCover = $petition->hasCover();
    $petitionCredit = $petition->image_credit ?? '';

    $btnSignNow = __('show.btn_sign_now');
    $boxSignTitle = __('show.box_sign_title');
    $googleContinue = __('show.google_continue');
    $orText = __('show.or');

    $loginUrl = lroute('login');

    $signPageUrl = lroute('petition.sign.page', ['slug' => $tr->slug ?? '', 'id' => $petition->id]);
    $stateless = request()->attributes->get('stateless_render') === true;
    $signTarget = $stateless ? $signPageUrl : '#signFormTop';

    $authHintSplit = __('show.auth_hint_split', ['login_url' => $loginUrl]);
    $authHintStack = __('show.auth_hint_stack', ['login_url' => $loginUrl]);

    $boxShoutbox = __('show.box_shoutbox');

    $canReport = ! $stateless;

    $guidelinesUrl = lroute('page.show', ['slug' => 'ethical-code']);
    $contactsUrl = lroute('page.show', ['slug' => 'contacts']);
    $reportUrl = lroute('report.store');

    $reportReasons = [
        'spam' => __('show.reason_spam'),
        'abuse' => __('show.reason_abuse'),
        'misinformation' => __('show.reason_misinfo'),
        'copyright' => __('show.reason_copyright'),
        'other' => __('show.reason_other'),
    ];

    $shareActions = [
        ['label' => 'Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u='.urlencode(request()->url()), 'external' => true, 'icon' => 'share-2'],
        ['label' => 'X', 'href' => 'https://x.com/intent/tweet?url='.urlencode(request()->url()).'&text='.urlencode($petitionTitle), 'external' => true, 'icon' => 'external-link'],
        ['label' => 'WhatsApp', 'href' => 'https://wa.me/?text='.urlencode($petitionTitle.' '.request()->url()), 'external' => true, 'icon' => 'message-square'],
    ];

    $shareActions[] = $canReport
        ? ['label' => 'Report', 'icon' => 'flag', 'sheet' => 'reportSheet']
        : ['label' => 'Report', 'icon' => 'flag', 'href' => $contactsUrl];

    $boxGoal = __('show.box_goal');
    $goalSignaturesText = __('show.goal_signatures', ['count' => number_format($goalCurrent)]);
    $goalLabelText = __('show.goal_label', ['count' => number_format($goalTotal)]);
    $goalPct = $goalCurrent > 0 && $goalTotal > 0 ? max(round($goalCurrent / $goalTotal * 100, 2), 1.5) : 0;
    $goalSignaturesWord = ltrim(str_replace(number_format($goalCurrent), '', $goalSignaturesText));
    $goalLabelPrefix = trim(str_replace(number_format($goalTotal), '', $goalLabelText));

    $boxLatest = __('show.box_latest');
    $latestEmpty = __('show.latest_empty');
    $latestBrowseAll = __('show.latest_browse_all');

    $boxInformation = __('show.box_information');
    $infoBy = __('show.info_by');
    $infoIn = __('show.info_in');
    $infoTarget = __('show.info_target');

    $boxTags = __('show.box_tags');
    $tagsEmpty = __('show.tags_empty');

    $boxEmbed = __('show.box_embed');
    $embedDirect = __('show.embed_direct');
    $embedHtml = __('show.embed_html');
    $embedForumNoTitle = __('show.embed_forum_no_title');
    $embedForumWithTitle = __('show.embed_forum_with_title');

    $boxWidgets = __('show.box_widgets');

    $crumbs = [
        ['label' => __('show.breadcrumb_home'), 'url' => lroute('home')],
        ['label' => __('show.breadcrumb_petitions'), 'url' => lroute('petitions.index')],
        ['label' => \Illuminate\Support\Str::limit($petitionTitle, 55)],
    ];
@endphp

@section('title', $pageTitle)
@section('og_title', $petitionTitle)
@section('og_description', \Illuminate\Support\Str::limit(strip_tags($tr->description ?? ''), 200))
@section('og_image', $petitionImg ?: asset('legacy/images-v2/freecause_logo_icon_clear.png'))

@push('head')
    @include('partials.hreflang')
@endpush

@section('content')
    <x-ui.section>

            <x-ui.breadcrumb class="mb-4" :items="$crumbs" />

            @if ($petition->status !== 'published')
                <div class="rounded-md border border-warning/30 bg-warning-soft px-4 py-3 mb-4 text-sm text-warning" role="status">
                    {{ __('petition.review_required') }}
                </div>
            @endif

            <div class="bg-white shadow-elev-1 rounded-md p-4 sm:p-6">
                <div data-confetti-on-load data-confetti-threshold="{{ in_array(true, [$pct>=100, $pct>=75, $pct>=50, $pct>=25], true) ? 1 : 0 }}" hidden></div>
                @if ($pct >= 80 && $pct < 100 && !$hasSigned)
                    <div class="flex items-center gap-2 bg-brand/5 border border-brand text-brand rounded-md px-4 py-3 mb-4 text-sm font-semibold" role="status">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                            <path d="M12 9v4"></path>
                            <path d="M12 17h.01"></path>
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        </svg>
                        <span>Only {{ number_format(max(0, $goalTotal - $goalCurrent)) }} signatures to go!</span>
                    </div>
                @endif

                <div class="flex items-center justify-between flex-wrap gap-2 mb-2" style="view-transition-name: petition-{{ $petition->id }}">
                    <h1 class="mb-0 text-xl font-bold break-words [overflow-wrap:anywhere]">
                        {{ $petitionTitle }}
                    </h1>
                    @include('petition.partials.organization-attribution')
                    @include('petition.partials.outcome')

                </div>

                <div class="flex flex-wrap items-center gap-2 mb-3">
                    @if ($petition->is_active)
                        <x-ui.badge variant="success">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Active
                        </x-ui.badge>
                    @endif
                    @if ($tr->is_featured)
                        <x-ui.badge variant="brand">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                            </svg>
                            Featured
                        </x-ui.badge>
                    @endif
                    @if ($petition->category?->name)
                        <x-ui.badge variant="neutral">
                            {{ $petition->category->name }}
                        </x-ui.badge>
                    @endif
                </div>

                <div class="flex items-center gap-2 mb-4">
                    @if ($canReport)
                        <button type="button" class="fcui-icon-btn" data-sheet-open="reportSheet" aria-haspopup="dialog" aria-label="Report" title="Report">
                            <x-lucide-flag aria-hidden="true" />
                        </button>
                    @else
                        <a class="fcui-icon-btn" href="{{ $contactsUrl }}" aria-label="Report" title="Report">
                            <x-lucide-flag aria-hidden="true" />
                        </a>
                    @endif

                    <button type="button" class="fcui-icon-btn md:hidden" data-sheet-open="petitionShare" aria-haspopup="dialog" aria-label="{{ __('show.share') }}">
                        <x-lucide-share-2 aria-hidden="true" />
                    </button>

                    @if (!$hasSigned)
                        <x-ui.button variant="danger" href="{{ $signTarget }}" data-sign-cta data-cached="{{ $stateless ? '1' : '0' }}">{{ $btnSignNow }}</x-ui.button>
                    @endif
                </div>

                @if (isset($errors) && ($errors->has('reportable_type') || $errors->has('reportable_id') || $errors->has('reason') || $errors->has('details')))
                    <x-ui.alert variant="error" class="mb-4">{{ __('show.report_failed') }}</x-ui.alert>
                @endif

                <div class="md:hidden mt-1 mb-4">
                    <div class="flex items-center justify-between gap-2 text-sm fc-tabular">
                        <span class="font-semibold"><span data-countup data-countup-to="{{ $goalCurrent }}">{{ number_format($goalCurrent) }}</span> {{ $goalSignaturesWord }}</span>
                        <span class="text-muted">{{ $goalLabelPrefix }} <span data-abbr data-abbr-value="{{ $goalTotal }}">{{ number_format($goalTotal) }}</span></span>
                    </div>
                    <div class="fc-progress-reveal h-2.5 bg-surface-2 rounded-full overflow-hidden mt-2">
                        <div class="h-full bg-success fcui-progress-fill" data-progress data-pct="{{ $goalPct }}" style="--pct: {{ $goalPct }}%"></div>
                    </div>
                </div>

                <div class="md:hidden sticky z-sticky -mx-6 bg-white" style="top: var(--fc-safe-top)">
                    <x-ui.chip-row>
                        <a class="fcui-chip fcui-tap" href="#petition-about">{{ __('pet.pdf_description') }}</a>
                        <a class="fcui-chip fcui-tap" href="#petition-comments">{{ $boxShoutbox }}</a>
                        <a class="fcui-chip fcui-tap" href="#petition-signatures">{{ $boxLatest }}</a>
                        @if (($related ?? collect())->isNotEmpty())
                            <a class="fcui-chip fcui-tap" href="#petition-related">{{ __('show.related') }}</a>
                        @endif
                    </x-ui.chip-row>
                </div>

                <div class="hidden md:flex flex-wrap items-center gap-2 mb-4">
                    <span class="text-sm font-semibold text-muted">{{ __('show.share') }}:</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(request()->url()) }}"
                       target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-xs px-3 py-1 text-sm font-semibold no-underline border-0 cursor-pointer transition-opacity duration-150 hover:opacity-85 hover:no-underline bg-facebook text-white"
                       aria-label="Share on Facebook">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg> Facebook
                    </a>
                    <a href="https://x.com/intent/tweet?url={{ urlencode(request()->url()) }}&text={{ urlencode($petitionTitle) }}"
                       target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-xs px-3 py-1 text-sm font-semibold no-underline border-0 cursor-pointer transition-opacity duration-150 hover:opacity-85 hover:no-underline bg-dark text-on-dark"
                       aria-label="Share on X">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.64 7.584H.47l8.6-9.835L0 1.154h7.594l5.243 6.932 6.064-6.933zm-1.29 19.496h2.039L6.487 3.24H4.3l13.31 17.409z"/></svg> X
                    </a>
                    <a href="https://wa.me/?text={{ urlencode($petitionTitle.' '.request()->url()) }}"
                       target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-xs px-3 py-1 text-sm font-semibold no-underline border-0 cursor-pointer transition-opacity duration-150 hover:opacity-85 hover:no-underline bg-whatsapp text-ink"
                       aria-label="Share on WhatsApp">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg> WhatsApp
                    </a>
                    @if ($canEdit)
                        <a href="{{ lroute('petition.edit', ['slug' => $tr->slug, 'id' => $petition->id]) }}"
                           class="inline-flex items-center gap-1 rounded-xs px-3 py-1 text-sm font-semibold no-underline border border-border cursor-pointer transition-opacity duration-150 hover:opacity-85 hover:no-underline bg-surface-2 text-ink"
                           aria-label="{{ __('show.edit') }}">
                            {{ __('show.edit') }}
                        </a>
                    @endif
                    <button type="button" id="copyPetitionLink" class="fc-share-copy inline-flex items-center gap-1 rounded-xs px-3 py-1 text-sm font-semibold no-underline border-0 cursor-pointer transition-opacity duration-150 hover:opacity-85 hover:no-underline"
                            data-url="{{ request()->url() }}"
                            data-copied-label="{{ __('show.link_copied') }}"
                            aria-label="Copy link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> {{ __('show.copy_link') }}
                    </button>
                </div>

                <x-ui.action-sheet id="petitionShare" :title="__('show.share')" :actions="$shareActions">
                    <button type="button" class="fcui-sheet-list__item w-full" data-copy="{{ request()->url() }}" data-copied-label="{{ __('show.link_copied') }}">
                        <x-lucide-copy aria-hidden="true" />
                        <span>{{ __('show.copy_link') }}</span>
                    </button>
                </x-ui.action-sheet>

                @if ($canReport)
                    <x-ui.bottom-sheet id="reportSheet" title="{{ __('show.report_title') }}" variant="center">
                        <form method="POST" action="{{ $reportUrl }}" class="flex flex-col gap-3">
                            @csrf
                            <input type="hidden" name="reportable_type" value="petition">
                            <input type="hidden" name="reportable_id" value="{{ $petition->id }}">

                            <fieldset class="flex flex-col m-0 p-0 border-0">
                                <legend class="fcui-field__label">{{ __('show.report_question') }}</legend>

                                @foreach (\App\Models\Report::reasons() as $reason)
                                    <label class="flex max-md:min-h-12 items-center gap-2 text-base">
                                        <input type="radio" name="reason" value="{{ $reason }}" required @checked($loop->first)>
                                        <span>{{ $reportReasons[$reason] }}</span>
                                    </label>
                                @endforeach
                            </fieldset>

                            <label class="fcui-field">
                                <span class="fcui-field__label">{{ __('show.report_details') }}</span>
                                <textarea name="details" rows="3" maxlength="500" class="fcui-input" placeholder="{{ __('show.report_details_ph') }}"></textarea>
                            </label>

                            <p class="mb-0 text-sm text-muted">
                                <a class="text-brand underline" href="{{ $guidelinesUrl }}">{{ __('show.report_guidelines') }}</a>.
                            </p>

                            <x-ui.button type="submit" class="w-full">{{ __('show.report_submit') }}</x-ui.button>
                        </form>
                    </x-ui.bottom-sheet>
                @endif

                <div class="flex flex-wrap -mx-3">
                    <div class="w-full lg:w-2/3 shrink-0 px-3">
                        @php $wv = $coverVariants; @endphp
                        @if (!$hasCover)
                        @php $initials = mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/u', trim(strip_tags($tr->title ?? ''))) ?: [], 0, 2)))); @endphp
                        <div class="fcui-cover-placeholder flex items-center justify-center rounded-lg h-56 md:h-[380px]" role="img" aria-label="{{ $petitionTitle }}">
                            <span class="text-5xl md:text-7xl font-bold text-white/90">{{ $initials ?: 'F' }}</span>
                        </div>
                        @else
                        <a href="#" class="relative block overflow-hidden rounded-lg bg-dark h-56 md:h-[380px]" data-bs-toggle="modal" data-bs-target="#imgModal">
                            @if ($wv)
                            <picture>
                                <source type="image/webp" srcset="{{ $wv['400'] }} 400w, {{ $wv['800'] }} 800w" sizes="100vw">
                                <img src="{{ $petitionImg }}" alt="" class="absolute inset-[-15px] w-[calc(100%+30px)] h-[calc(100%+30px)] object-cover blur-[18px] opacity-[0.55] rounded-none" aria-hidden="true" loading="lazy" decoding="async">
                            </picture>
                            <picture>
                                <source type="image/webp" srcset="{{ $wv['400'] }} 400w, {{ $wv['800'] }} 800w" sizes="(min-width: 992px) 640px, 100vw">
                                <img src="{{ $petitionImg }}" alt="{{ $petitionTitle }}" data-blurup class="fcui-blurup relative z-10 block w-full h-56 md:h-[380px] object-contain" loading="eager" fetchpriority="high" decoding="async">
                            </picture>
                            @else
                            <img src="{{ $petitionImg }}" alt="" class="absolute inset-[-15px] w-[calc(100%+30px)] h-[calc(100%+30px)] object-cover blur-[18px] opacity-[0.55] rounded-none" aria-hidden="true" loading="lazy" decoding="async">
                            <img src="{{ $petitionImg }}" alt="{{ $petitionTitle }}" data-blurup class="fcui-blurup relative z-10 block w-full h-56 md:h-[380px] object-contain" loading="eager" fetchpriority="high" decoding="async">
                            @endif
                            @if ($petitionCredit)
                                <span class="absolute right-2 bottom-2 rounded-md bg-dark/55 px-2 py-1 text-xs text-on-dark">{{ $petitionCredit }}</span>
                            @endif
                        </a>
                        @endif

                        <div id="petition-about" class="mt-4 scroll-mt-16">
                            <div class="text-base leading-[1.75] text-ink [&_p]:mb-3 [&_p]:text-sm [&_p]:leading-[1.75] [&_ul]:my-2.5 [&_ul]:mt-0 [&_ul]:mb-3.5 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:my-2.5 [&_ol]:mt-0 [&_ol]:mb-3.5 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:mb-1.5 [&_li]:text-sm [&_li]:leading-[1.75] [&_li>ul]:mt-2 [&_li>ol]:mt-2 [&_h3]:mt-4 [&_h3]:text-base [&_h3]:font-bold [&_h4]:mt-3.5 [&_h4]:text-sm [&_h4]:font-bold">
                                {!! $tr->description !!}
                            </div>
                            @if ($youtubeId)
                                <iframe src="https://www.youtube-nocookie.com/embed/{{ $youtubeId }}" title="{{ __('show.video_title', ['title' => $petitionTitle]) }}" class="mt-4 block w-full aspect-video rounded-md border-0" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                            @endif
                        </div>

                        {{-- ad: 468x60, left column, between about and bottom sign form, tablet+ only --}}
                        <x-ads.slot name="banner468" class="hidden md:block text-center my-6" />

                        <div class="border-t border-border my-6"></div>

                        <div id="signFormBottom" class="bg-surface-2 rounded-md p-4 sm:p-6 border border-border">
                            <h2 class="text-lg font-semibold">{{ $boxSignTitle }}</h2>

                            @include('partials.gradient-divider')

                            @if (!$isAuthed)
                                <div class="flex gap-2 justify-center flex-wrap my-4">
                                    <a class="btn fc-google-btn fc-oauth-btn"
                                        href="{{ lroute('oauth.google', ['flow' => 'petition', 'petition_id' => $petition->id, 'slug' => $tr->slug]) }}">
                                        <img src="{{ asset('legacy/images-v2/google-g.svg') }}" alt="" width="18" height="18" class="w-[18px] h-[18px] block shrink-0" aria-hidden="true">
                                        {{ $googleContinue }}
                                    </a>
                                </div>

                                <div class="relative text-center my-3 before:absolute before:left-0 before:right-0 before:top-1/2 before:h-px before:bg-border"><span class="relative bg-surface-2 px-2.5 font-bold">{{ $orText }}</span></div>

                                <p class="mb-4">{!! $authHintSplit !!}</p>
                            @endif

                            @include('petition.partials._sign_form', [
                                'variant' => 'split',
                                'petition' => $petition,
                                'tr' => $tr,
                                'locale' => $locale,
                                'hasSigned' => $hasSigned,
                                'content' => $formContent,
                            ])
                        </div>

                        <div id="petition-comments" class="mt-6 scroll-mt-16">
                            <h2 class="text-lg font-semibold">{{ $boxShoutbox }}</h2>
                            @include('partials.gradient-divider')

                            <div class="fc-reveal fc-stagger">
                                @forelse ($shoutbox as $comment)
                                    <div class="flex items-start gap-4 py-4 {{ !$loop->last ? 'border-b' : '' }}" style="--fc-i: {{ min($loop->index, 12) }}">
                                        <div class="grow">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                @if ($comment->user)
                                                    <a class="font-semibold text-sm text-brand"
                                                        href="{{ lroute('user.profile', ['slug' => \Illuminate\Support\Str::slug($comment->user->name ?? 'user') ?: 'user', 'id' => $comment->user_id]) }}">
                                                        {{ $comment->name ?? $comment->user->name ?? __('sig.anonymous') }}
                                                    </a>
                                                @else
                                                    <strong class="text-sm">{{ $comment->name ?? __('sig.anonymous') }}</strong>
                                                @endif
                                                <span class="text-muted ms-auto text-xs">
                                                    {{ optional($comment->created_at)->format('j F Y') }}
                                                </span>
                                            </div>
                                            <p class="mb-0 mt-1 text-muted text-sm">
                                                "{{ $comment->text }}"
                                            </p>

                                            @if ($canReport)
                                                <details class="mt-1">
                                                    <summary class="fcui-tap inline-flex cursor-pointer items-center gap-1 text-xs text-muted">
                                                        <x-lucide-flag class="w-4 h-4" aria-hidden="true" />
                                                        Report
                                                    </summary>

                                                    <form method="POST" action="{{ $reportUrl }}" class="mt-2 flex flex-col gap-2">
                                                        @csrf
                                                        <input type="hidden" name="reportable_type" value="comment">
                                                        <input type="hidden" name="reportable_id" value="{{ $comment->id }}">

                                                        <label class="fcui-field">
                                                            <span class="fcui-field__label">{{ __('show.report_comment_title') }}</span>
                                                            <select name="reason" class="fcui-input" required>
                                                                @foreach (\App\Models\Report::reasons() as $reason)
                                                                    <option value="{{ $reason }}">{{ $reportReasons[$reason] }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>

                                                        <textarea name="details" rows="2" maxlength="500" class="fcui-input" placeholder="{{ __('show.report_details') }}"></textarea>

                                                        <x-ui.button type="submit" variant="secondary" size="sm" class="self-start">{{ __('show.report_submit') }}</x-ui.button>
                                                    </form>
                                                </details>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <x-ui.empty :text="__('sig.no_comments')" class="fcui-empty--compact" />
                                @endforelse

                                @if ($shoutbox->hasPages())
                                    <x-ui.pagination :paginator="$shoutbox" />
                                @endif
                            </div>
                        </div>

                        {{-- ad: native, left column, last element before related petitions, full column width --}}
                        <x-ads.slot name="native" class="mt-6" />
                    </div>

                    <div class="w-full lg:w-1/3 shrink-0 px-3">
                        <div id="signFormTop" class="bg-surface-2 rounded-md p-4 sm:p-6 mb-6 border border-border">
                            <h2 class="text-lg font-semibold">{{ $boxSignTitle }}</h2>
                            @include('partials.gradient-divider')

                            @if (!$isAuthed)
                                <div class="flex gap-2 justify-center flex-wrap my-4">
                                    <a class="btn fc-google-btn fc-oauth-btn"
                                        href="{{ lroute('oauth.google', ['flow' => 'petition', 'petition_id' => $petition->id, 'slug' => $tr->slug]) }}">
                                        <img src="{{ asset('legacy/images-v2/google-g.svg') }}" alt="" width="18" height="18" class="w-[18px] h-[18px] block shrink-0" aria-hidden="true">
                                        {{ $googleContinue }}
                                    </a>
                                </div>

                                <div class="relative text-center my-3 before:absolute before:left-0 before:right-0 before:top-1/2 before:h-px before:bg-border"><span class="relative bg-surface-2 px-2.5 font-bold">{{ $orText }}</span></div>

                                <p class="mb-4">{!! $authHintStack !!}</p>
                            @endif

                            @include('petition.partials._sign_form', [
                                'variant' => 'stack',
                                'petition' => $petition,
                                'tr' => $tr,
                                'locale' => $locale,
                                'hasSigned' => $hasSigned,
                                'content' => $formContent,
                            ])
                        </div>

                        {{-- ad: 300x250, sidebar, between top sign form and goal box --}}
                        <x-ads.slot name="rectangle" class="text-center mb-6" />

                        <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 mb-6 hover:shadow-elev-2 transition-shadow duration-150">
                            <h2 class="text-lg font-semibold">{{ $boxGoal }}</h2>
                            @include('partials.gradient-divider')

                            <div class="fc-progress-reveal h-2.5 md:h-1.5 bg-surface-2 rounded-full overflow-hidden mt-4">
                                <div class="h-full bg-success fcui-progress-fill" data-progress data-pct="{{ $goalPct }}" style="--pct: {{ $goalPct }}%"></div>
                            </div>

                            <div class="flex justify-between mt-2 text-sm">
                                <div class="fc-tabular"><span data-countup data-countup-to="{{ $goalCurrent }}">{{ number_format($goalCurrent) }}</span> {{ $goalSignaturesWord }}</div>
                                <div class="fc-tabular text-muted">{{ $goalLabelPrefix }} <span data-abbr data-abbr-value="{{ $goalTotal }}">{{ number_format($goalTotal) }}</span></div>
                            </div>

                            @if (($recentSignatureCount ?? 0) > 0)
                                <div class="mt-3 flex items-center gap-2 text-sm text-success font-medium">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                    <span>{{ __('show.social_proof', ['count' => number_format($recentSignatureCount)]) }}</span>
                                </div>
                            @endif
                        </div>

                        <div id="petition-signatures" class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 mb-6 hover:shadow-elev-2 transition-shadow duration-150 scroll-mt-16">
                            <div class="font-bold text-base flex items-center justify-between">
                                <span>{{ $boxLatest }}</span>
                            </div>
                            @include('partials.gradient-divider')

                            <div class="text-sm mt-4 fc-reveal fc-stagger">
                                @forelse($latest as $i => $sig)
                                    <div class="py-2 border-b border-border last:border-b-0" style="--fc-i: {{ min($loop->index, 12) }}">
                                        <div class="font-bold mb-0.5">
                                            {{ $sig->created_at?->format('j F Y') }}
                                        </div>
                                        <div>
                                            <strong>{{ $goalCurrent - $i }}.</strong>
                                            <a href="{{ lroute('petition.signatures', ['slug' => $tr->slug, 'id' => $petition->id]) }}"
                                                class="text-brand">{{ $sig->display_name ?? __('sig.anonymous') }}</a>
                                            <span class="text-muted">|</span>
                                            <span>{{ $sig->text ?? __('sig.default_comment') }}</span>
                                        </div>
                                    </div>
                                @empty
                                    <div class="flex flex-col items-center justify-center text-center py-6 text-muted">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="mb-2 opacity-70">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="9" cy="7" r="4"></circle>
                                            <line x1="19" y1="8" x2="19" y2="14"></line>
                                            <line x1="22" y1="11" x2="16" y2="11"></line>
                                        </svg>
                                        <div class="text-sm">{{ $latestEmpty }}</div>
                                    </div>
                                @endforelse

                                <x-ui.button variant="danger" size="sm" class="mt-2"
                                    :href="lroute('petition.signatures', ['slug' => $tr->slug, 'id' => $petition->id])">{{ $latestBrowseAll }}</x-ui.button>
                            </div>
                        </div>

                        <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 mb-6 hover:shadow-elev-2 transition-shadow duration-150">
                            <h2 class="text-lg font-semibold">{{ $boxInformation }}</h2>
                            @include('partials.gradient-divider')

                            <div class="mt-4 text-sm leading-[1.8]">
                                <div class="flex justify-between gap-2">
                                    <strong>{{ $infoBy }}</strong>
                                    @if ($petition->user)
                                        <a href="{{ lroute('user.profile', ['slug' => \Illuminate\Support\Str::slug($petition->user->name ?? 'user') ?: 'user', 'id' => $petition->user_id]) }}"
                                            class="text-brand">
                                            {{ $petition->user->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </div>

                                <div class="flex justify-between gap-2">
                                    <strong>{{ $infoIn }}</strong>
                                    <a href="{{ lroute('petitions.byCategory', ['categorySlug' => $petition->category?->slug ?? '-', 'category' => $petition->category?->id ?? 0]) }}"
                                        class="text-brand">
                                        {{ $petition->category?->name ?? '-' }}
                                    </a>
                                </div>

                                <div class="mt-2">
                                    <strong>{{ $infoTarget }}</strong><br>
                                    {{ $petition->target ?? '-' }}
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 mb-6 hover:shadow-elev-2 transition-shadow duration-150">
                            <h2 class="text-lg font-semibold">{{ $boxTags }}</h2>
                            @include('partials.gradient-divider')
                            <div class="mt-4 text-sm">
                                @if ($petition->tags)
                                    @foreach (explode(',', $petition->tags) as $tag)
                                        @if (trim($tag))
                                            <span class="badge bg-secondary me-1 mb-1 font-normal text-sm">{{ trim($tag) }}</span>
                                        @endif
                                    @endforeach
                                @else
                                    <span class="text-muted">{{ $tagsEmpty }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 mb-6 hover:shadow-elev-2 transition-shadow duration-150">
                            <h2 class="text-lg font-semibold">{{ $boxEmbed }}</h2>
                            @include('partials.gradient-divider')

                            <div class="mt-4 text-sm">
                                <div class="mb-2"><strong>{{ $embedDirect }}</strong></div>
                                <input class="block w-full px-3 py-1.5 text-base leading-normal text-ink bg-white border border-border rounded-md appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25 mb-4" aria-label="{{ $embedDirect }}" value="{{ $directLink }}" readonly>

                                <div class="mb-2"><strong>{{ $embedHtml }}</strong></div>
                                <input class="block w-full px-3 py-1.5 text-base leading-normal text-ink bg-white border border-border rounded-md appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25 mb-4" aria-label="{{ $embedHtml }}"
                                    value='<a href="{{ $directLink }}">{{ $petitionTitle }}</a>' readonly>

                                <div class="mb-2"><strong>{{ $embedForumNoTitle }}</strong></div>
                                <input class="block w-full px-3 py-1.5 text-base leading-normal text-ink bg-white border border-border rounded-md appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25 mb-4" aria-label="{{ $embedForumNoTitle }}" value='[URL="{{ $directLink }}"][/URL]' readonly>

                                <div class="mb-2"><strong>{{ $embedForumWithTitle }}</strong></div>
                                <input class="block w-full px-3 py-1.5 text-base leading-normal text-ink bg-white border border-border rounded-md appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25" aria-label="{{ $embedForumWithTitle }}" value='[URL]{{ $directLink }}[/URL]' readonly>
                            </div>
                        </div>

                        <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 hover:shadow-elev-2 transition-shadow duration-150">
                            <button id="widgetsToggle" class="w-full border-0 bg-transparent p-0 inline-flex items-center cursor-pointer focus:outline-none focus:shadow-none" type="button"
                                data-bs-toggle="collapse" data-bs-target="#widgetsBody" aria-expanded="false"
                                aria-controls="widgetsBody">
                                <span class="font-bold text-base mb-0 flex items-center gap-2">
                                    {{ $boxWidgets }}
                                    <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="leading-none cursor-pointer"><path d="m6 9 6 6 6-6"/></svg>
                                </span>
                            </button>

                            @include('partials.gradient-divider')

                            <div id="widgetsBody" class="collapse">
                                <div class="mt-4">
                                    @foreach (['728x90', '468x60', '336x280', '125x125'] as $widgetType)
                                        @php
                                            $widgetCode = '<script src="'.url("/widgets/pbadge/{$petition->id}/{$widgetType}").'"></script>';
                                        @endphp
                                        <div class="mb-2"><strong>{{ str_replace('x', '×', $widgetType) }}</strong></div>
                                        <textarea class="block w-full px-3 py-1.5 text-base leading-normal text-ink bg-white border border-border rounded-md appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25 mb-4" rows="3" readonly aria-label="{{ $widgetType }} embed code">{{ $widgetCode }}</textarea>
                                        <div class="mb-6 overflow-auto">
                                            <img src="{{ asset("legacy/images/widget-pbadge-{$widgetType}-example.png") }}"
                                                alt="{{ $widgetType }} petition widget preview" loading="lazy" decoding="async">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        @auth
                            @if ((int) auth()->id() !== (int) $petition->user_id && app(\App\Policies\PetitionPolicy::class)->setOutcome(auth()->user(), $petition))
                                <a class="text-brand underline block my-4" href="{{ lroute('petition.outcome', ['slug'=>$tr->slug, 'id'=>$petition->id]) }}">{{ __('organizations.update_outcome') }}</a>
                            @endif
                            @if ((int) auth()->id() === (int) $petition->user_id)
                                <div class="bg-white rounded-md p-4 sm:p-6 shadow-elev-1 my-6 hover:shadow-elev-2 transition-shadow duration-150">
                                    <h2 class="text-lg font-semibold">{{ __('show.operations') }}</h2>
                                    @include('partials.gradient-divider')

                                    <div class="text-sm leading-[1.9]">
                                        @if ($canEdit)
                                            <div class="mb-2">
                                                <a class="text-brand"
                                                    href="{{ lroute('petition.edit', ['slug' => $tr->slug, 'id' => $petition->id]) }}">{{ __('show.edit') }}</a>
                                            </div>
                                        @endif

                                        <div>
                                            {{ __('show.download_signatures') }} :
                                            <a class="text-brand"
                                                href="{{ lroute('petition.download.txt', ['slug' => $tr->slug, 'id' => $petition->id]) }}">TXT</a>
                                            <span class="text-muted"> </span>
                                            <a class="text-brand"
                                                href="{{ lroute('petition.download.pdf', ['slug' => $tr->slug, 'id' => $petition->id]) }}">PDF</a>
                                            <span class="text-muted"> </span>
                                            <a class="text-brand"
                                                href="{{ lroute('petition.download.csv', ['slug' => $tr->slug, 'id' => $petition->id]) }}">CSV</a>
                                        </div>

                                        @if ($petition->status === 'published' && $petition->is_active)
                                            <div class="mt-2">
                                                @include('partials.petition-withdraw', ['slug' => $tr->slug, 'id' => $petition->id])
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endauth

                    </div>
                </div>
            </div>

            @if (($related ?? collect())->isNotEmpty())
                <div id="petition-related" class="mt-10 scroll-mt-16">
                    <h2 class="text-lg font-bold mb-4">{{ __('show.related') }}</h2>
                    <div class="flex flex-wrap -mx-3 fc-reveal fc-stagger">
                        @foreach ($related as $rel)
                            <div class="w-full md:w-1/3 shrink-0 px-3 mb-6" style="--fc-i: {{ min($loop->index, 12) }}">
                                <a href="{{ lroute('petition.show', ['slug' => $rel->tr_slug, 'id' => $rel->id]) }}"
                                   class="block h-full no-underline bg-white rounded-md shadow-elev-1 overflow-hidden fc-lift">
                                    <div class="p-4">
                                        <div class="text-ink font-semibold text-sm leading-snug break-words [overflow-wrap:anywhere]">{{ $rel->tr_title }}</div>
                                        <div class="mt-2 text-sm text-muted fc-tabular">
                                            <span data-countup data-countup-to="{{ (int) ($rel->signature_count ?? 0) }}">{{ number_format($rel->signature_count ?? 0) }}</span> {{ __('list.signatures') }}
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <button type="button" class="fcui-scrolltop max-md:bottom-32" data-scroll-top aria-label="{{ __('ui.back_to_top') }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="12" y1="19" x2="12" y2="5"></line>
                    <polyline points="5 12 12 5 19 12"></polyline>
                </svg>
            </button>

        </x-ui.section>

    @if (!$hasSigned)
        <div data-sign-cta data-cached="{{ $stateless ? '1' : '0' }}">
            <x-ui.sticky-action :href="$signTarget" :label="$btnSignNow" :context="$goalSignaturesText" />
        </div>
    @endif

    <div class="modal fade" id="imgModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content p-2">
                <div class="modal-body p-0 position-relative">
                    <button type="button" class="btn-close position-absolute right-2 top-2 z-[2]"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                    <img src="{{ $petitionImg }}" class="img-fluid w-100" loading="lazy" decoding="async" alt="{{ $petitionTitle }}">
                </div>
            </div>
        </div>
    </div>
@endsection

@push('head')
    @if ($hasCover)
        @if ($coverVariants)
            <link rel="preload" as="image" href="{{ $coverVariants['800'] }}" type="image/webp"
                imagesrcset="{{ $coverVariants['400'] }} 400w, {{ $coverVariants['800'] }} 800w"
                imagesizes="(min-width: 992px) 640px, 100vw" fetchpriority="high">
        @else
            <link rel="preload" as="image" href="{{ $petitionImg }}" fetchpriority="high">
        @endif
    @endif
@endpush

@push('scripts')
    <script src="{{ asset('legacy/js-v2/bootstrap.bundle.min.js') }}" defer></script>
    <script src="{{ asset('legacy/js-v2/petition-show.js') }}"></script>
@endpush
