@extends('layouts.legacy')
@section('title', __('organizations.find_organization'))
@section('content')
<section class="container py-10 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.find_organization') }}</h1>
    @include('organizations._claim-search-form')
    <ul class="space-y-4">
        @forelse($organizations as $org)
            <li class="border border-border rounded-lg p-4 flex flex-wrap gap-4 items-center">
                @if($org->status === 'verified' && $org->logo_path)<img src="{{ Storage::disk('public')->url($org->logo_path) }}" width="48" height="48" loading="lazy" class="size-12 object-cover rounded-sm" alt="">@else<span class="size-12 flex items-center justify-center bg-surface-muted rounded-sm" aria-hidden="true">{{ mb_substr($org->name,0,1) }}</span>@endif
                <div class="min-w-0 flex-1"><h2 class="text-xl font-semibold break-words">{{ $org->name }}</h2><p>{{ $countries[$org->country] ?? $org->country }} · {{ __('organizations.'.$org->status) }}</p></div>
                @if($org->status === 'unclaimed')<x-ui.button :href="lroute('organizations.claim', ['slug'=>$org->slug])">{{ __('organizations.claim_page') }}</x-ui.button>@else<a class="underline" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}">{{ __('organizations.view_page') }}</a>@endif
            </li>
        @empty
            @if($q !== '')<li>{{ __('organizations.no_results') }}</li>@endif
        @endforelse
    </ul>
    @if($organizations->isEmpty() && (config('organizations.public_launch') || auth()->check()))<a class="underline block mt-6" href="{{ lroute('organizations.apply') }}">{{ __('organizations.not_listed') }}</a>@endif
    <a class="underline block mt-8" href="{{ lroute('organizations.terms') }}">{{ __('organizations.terms') }}</a>
</section>
@endsection
