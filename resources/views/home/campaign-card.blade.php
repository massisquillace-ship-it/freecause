@php
    $campaignUrl = lroute('petition.show', ['slug'=>$petition->tr_slug, 'id'=>$petition->id]);
    $goal = max(1, (int) $petition->goal_signatures);
    $progress = min(100, (int) round($petition->signature_count / $goal * 100));
@endphp
<article class="bg-white border border-border rounded-lg shadow-elev-1 overflow-hidden min-w-0 h-full flex flex-col">
    <a href="{{ $campaignUrl }}" class="block shrink-0" tabindex="-1" aria-hidden="true">
        <img src="{{ $petition->coverUrl() }}" alt="" width="640" height="360" loading="lazy" decoding="async" class="w-full aspect-[16/9] object-cover bg-surface-muted" data-home-petition-cover data-cover-fallback="{{ asset('legacy/images/demo-featured.jpg') }}">
    </a>
    <div class="p-4 flex flex-col flex-1">
    @if($goalReached ?? false)<span class="bg-success-soft text-ink rounded-full px-2 py-1 text-xs self-start mb-3">{{ __('home.victory_badge') }}</span>@endif
    <h3 class="text-base leading-snug font-semibold break-words mb-3"><a href="{{ $campaignUrl }}" class="text-ink hover:text-brand">{{ $petition->tr_title }}</a></h3>
    @include('home.author', ['petition'=>$petition])
    <div class="mt-auto pt-4">
        <div class="progress mb-2"><div class="progress-bar fcui-progress-fill bg-brand" style="--pct: {{ $progress }}%" role="progressbar" aria-label="{{ __('home.signatures_label') }}" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"></div></div>
        <p class="flex justify-between gap-2 text-sm text-muted fc-tabular"><span>{{ number_format($petition->signature_count) }} {{ __('home.signatures_label') }}</span><span>{{ __('home.goal_label') }} {{ number_format($goal) }}</span></p>
    </div>
    </div>
</article>
