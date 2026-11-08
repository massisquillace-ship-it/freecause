@extends('layouts.legacy')
@section('title', __('organizations.terms'))
@section('content')
<section class="container py-12 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.terms') }}</h1>
    <p class="text-muted mb-6">{{ config('organizations.terms_version') }}</p>
    <article class="space-y-5 leading-relaxed break-words">
        @foreach(preg_split('/\n\s*\n/u', $terms ?: __('organizations.terms_unavailable')) as $paragraph)
            @php($lines = explode("\n", $paragraph, 2))
            @if(preg_match('/^\d+\. /u', $lines[0]))
                <section><h2 class="text-xl font-semibold mb-2">{{ $lines[0] }}</h2><p>{{ $lines[1] ?? '' }}</p></section>
            @else
                <p class="whitespace-pre-line">{{ $paragraph }}</p>
            @endif
        @endforeach
    </article>
    <nav class="mt-8 flex flex-wrap gap-4" aria-label="{{ __('organizations.terms') }}">
        <a class="underline" href="{{ url('/'.app()->getLocale().'/terms-of-service') }}">{{ __('pages.terms_of_service') }}</a>
        <a class="underline" href="{{ url('/'.app()->getLocale().'/privacy-policy') }}">{{ __('pages.privacy_policy') }}</a>
        <a class="underline" href="mailto:hello@freecause.com">hello@freecause.com</a>
    </nav>
</section>
@endsection
