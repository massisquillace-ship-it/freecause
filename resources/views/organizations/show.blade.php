@extends('layouts.legacy')
@section('title', $org->name.' — FreeCause')
@section('og_title', $org->name.' — FreeCause')
@section('og_description', \Illuminate\Support\Str::limit($org->localized('tagline') ?: $org->localized('description'), 160))
@section('og_image', $org->cover_path || $org->logo_path ? Storage::disk('public')->url($org->cover_path ?: $org->logo_path) : asset('legacy/images-v2/freecause_logo_icon_clear.png'))
@section('canonical', $profileUrl)
@section('og_url', $profileUrl)
@push('preload')
@php
    $coverVariants = \App\Support\ImageVariants::urls($org->cover_path);
    $coverSrcset = $coverVariants ? $coverVariants['400'].' 400w, '.$coverVariants['800'].' 800w, '.Storage::disk('public')->url($org->cover_path).' 1500w' : null;
    $coverSizes = '(max-width: 1024px) calc(100vw - 24px), 1000px';
@endphp
@if($org->cover_path)<link rel="preload" href="{{ Storage::disk('public')->url($org->cover_path) }}" as="image" fetchpriority="high" @if($coverSrcset) imagesrcset="{{ $coverSrcset }}" imagesizes="{{ $coverSizes }}" @endif>@endif
<link rel="preload" href="{{ asset('fonts/Inter/InterVariable-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
@endpush
@section('content')
<article class="container py-6 max-w-5xl" data-organization-share>
    @if($org->cover_path)
        <img src="{{ Storage::disk('public')->url($org->cover_path) }}" alt="{{ __('organizations.cover') }}" @if($coverSrcset) srcset="{{ $coverSrcset }}" sizes="{{ $coverSizes }}" @endif width="1500" height="500" fetchpriority="high" class="w-full aspect-[3/1] object-cover rounded-lg">
    @else
        <div class="w-full aspect-[3/1] rounded-lg {{ ['bg-surface-muted','bg-brand-soft','bg-surface-muted'][hexdec(substr(hash('sha256', $org->slug),0,2)) % 3] }}" aria-hidden="true"></div>
    @endif
    <header class="py-6 sm:pt-0">
        <div class="flex flex-col sm:flex-row gap-4 items-start sm:-mt-8 relative">
            @if($org->logo_path)
                <img class="size-24 object-cover rounded-lg shrink-0 border-4 border-surface" src="{{ Storage::disk('public')->url($org->logo_path) }}" alt="{{ __('organizations.logo') }}" width="400" height="400">
            @else
                <div class="size-24 flex items-center justify-center bg-surface-muted rounded-lg text-3xl font-bold shrink-0" aria-hidden="true">{{ mb_substr($org->name, 0, 1) }}</div>
            @endif
            <div class="min-w-0 sm:pt-10">
                <h1 class="text-3xl sm:text-4xl font-bold break-words">{{ $org->name }}</h1>
                <p class="mt-2">{{ __('organizations.'.$org->type) }} · {{ $org->country }}@if($org->territory) · {{ $org->territory }}@endif</p>
                <p class="font-semibold mt-3">✓ {{ __('organizations.verified') }}</p>
                @if($org->founding_partner)<p class="mt-2">★ {{ __('organizations.founding_badge') }}</p>@endif
            </div>
        </div>
        <p class="mt-4 text-sm">{{ __('organizations.verification_explanation') }}</p>
        @if($org->localized('tagline'))<p class="text-xl mt-6">{{ $org->localized('tagline') }}</p>@endif
        <ul class="flex flex-wrap gap-2 mt-4">@foreach($org->causes as $cause)<li class="border border-border rounded-sm px-3 py-1">{{ $cause->label() }}</li>@endforeach</ul>
        <div class="flex flex-wrap items-start gap-4 mt-6">
            @if($org->hasApprovedDonation())
                <div class="border border-border rounded-lg p-4"><x-ui.button :href="$org->donation_url" target="_blank" rel="noopener noreferrer nofollow sponsored">{{ __('organizations.donate') }}</x-ui.button><p class="text-sm mt-3">{{ __('organizations.donation_disclosure') }}</p></div>
            @endif
            <div class="space-y-3">
                <x-ui.button type="button" data-share-url="{{ $profileUrl }}" data-share-title="{{ $org->name }}">{{ __('organizations.share') }}</x-ui.button>
                <button type="button" class="block underline" data-copy-text="{{ $profileUrl }}">{{ __('organizations.copy_link') }}</button>
                <p data-share-status role="status" data-copied="{{ __('organizations.copied') }}" data-failed="{{ __('organizations.copy_failed') }}"></p>
                <label class="block" hidden data-copy-fallback>{{ __('organizations.copy_link') }}<input class="w-full border border-border p-2" readonly aria-label="{{ __('organizations.copy_link') }}"></label>
            </div>
        </div>
    </header>
    <div class="grid md:grid-cols-3 gap-8">
        <div class="md:col-span-2 min-w-0">
            @if($org->localized('description'))<section class="mb-8"><h2 class="text-2xl font-bold mb-4">{{ __('organizations.description') }}</h2><p class="whitespace-pre-line break-words">{{ $org->localized('description') }}</p></section>@endif
            <section class="border-t border-border pt-6"><p class="mb-4">{{ trans_choice('organizations.petition_count', 0, ['count'=>0]) }} · {{ trans_choice('organizations.signature_count', 0, ['count'=>0]) }}</p><h2 class="text-2xl font-bold mb-4">{{ __('organizations.campaigns') }}</h2><p>{{ __('organizations.empty_campaigns') }}</p></section>
        </div>
        <aside class="space-y-6 min-w-0">
            @if($org->country === 'IT' && $org->tax_code)
                <section class="border border-border rounded-lg p-4"><h2 class="text-xl font-bold mb-3">{{ __('organizations.five_title') }}</h2><p>{{ __('organizations.five_help') }}</p><p class="mt-3"><code class="font-mono text-2xl font-bold break-all text-ink">{{ $org->tax_code }}</code></p><button type="button" class="underline mt-3" data-copy-text="{{ $org->tax_code }}">{{ __('organizations.copy_tax') }}</button></section>
            @endif
            @if($org->website)<a class="block underline break-words" href="{{ $org->website }}" target="_blank" rel="noopener noreferrer nofollow">{{ __('organizations.website') }}</a>@endif
            @if($org->email_public)<a class="block underline break-all" href="mailto:{{ $org->email_public }}">{{ $org->email_public }}</a>@endif
            @foreach($org->social_links ?? [] as $social => $url)<a class="block underline" href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow">{{ ucfirst($social) }}</a>@endforeach
            <a class="block underline" href="mailto:{{ \App\Models\Organization::supportEmail() }}?{{ http_build_query(['subject'=>__('organizations.report_subject', ['slug'=>$org->slug]), 'body'=>$profileUrl], '', '&', PHP_QUERY_RFC3986) }}">{{ __('organizations.report') }}</a>
        </aside>
    </div>
</article>
@endsection
@push('scripts')
@vite('resources/js/organization-share.js')
@endpush
