@extends('layouts.legacy')
@section('title', __('organizations.nav'))
@section('content')
<section class="container py-12">
    <div class="max-w-3xl mx-auto">
        <h1 class="text-4xl font-bold mb-6">{{ __('organizations.headline') }}</h1>
        <p class="text-lg mb-6">{{ __('organizations.intro') }}</p>
        <ul class="grid sm:grid-cols-3 gap-4 my-8">
            @foreach(['benefit_identity','benefit_campaigns','benefit_discovery'] as $benefit)<li class="border border-border rounded-lg p-4">{{ __('organizations.'.$benefit) }}</li>@endforeach
        </ul>
        <p class="mb-4">{{ __('organizations.verification_explanation') }}</p>
        <p class="mb-6">{{ __('organizations.founding_help') }}</p>
        @if($applicationsOpen)
            <x-ui.button :href="lroute('organizations.apply')">{{ __('organizations.apply') }}</x-ui.button>
        @else
            <p class="p-4 bg-surface-muted border border-border rounded-lg">{{ __('organizations.applications_closed') }}</p>
        @endif
        <a class="block underline mt-4" href="{{ lroute('organizations.terms') }}">{{ __('organizations.terms') }}</a>
        @if($owned->isNotEmpty())
            <h2 class="text-2xl font-bold mt-10 mb-4">{{ __('organizations.my_organizations') }}</h2>
            <ul class="space-y-4">
                @foreach($owned as $org)
                    <li><a class="underline" href="{{ lroute('organizations.manage', ['slug' => $org->slug]) }}">{{ $org->name }}</a> — {{ __('organizations.'.$org->status) }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endsection
