@extends('layouts.legacy')
@section('title', __('organizations.nav'))
@section('content')
<section class="container py-12">
    <div class="max-w-3xl mx-auto">
        <h1 class="text-4xl font-bold mb-6">{{ __('organizations.headline') }}</h1>
        <p class="text-lg mb-6">{{ __('organizations.intro') }}</p>
        @include('organizations._claim-search-form')
        @if($applicationsOpen)
            <a class="underline block mb-8" href="{{ lroute('organizations.apply') }}">{{ __('organizations.not_listed') }}</a>
        @endif
        <ol class="space-y-3 my-8 list-decimal ps-5">
            @foreach(['claim','campaign','outcome'] as $step)<li>{{ __('organizations.steps.'.$step) }}</li>@endforeach
        </ol>
        <section class="border-t border-border pt-6">
            <h2 class="text-xl font-semibold mb-3">{{ __('organizations.verification.title') }}</h2>
            <p class="mb-4">{{ __('organizations.verification.body') }}</p>
            <p>{{ __('organizations.verification.founding') }}</p>
        </section>
        @if($owned->isNotEmpty())
            <h2 class="text-2xl font-bold mt-10 mb-4">{{ __('organizations.my_organizations') }}</h2>
            <ul class="space-y-4">
                @foreach($owned as $org)
                    <li><a class="underline" href="{{ lroute('organizations.manage', ['slug' => $org->slug]) }}">{{ $org->name }}</a> — {{ __('organizations.'.$org->status) }} · {{ __('organization_members.'.($org->roleFor(auth()->user()) ?? 'admin')) }} · {{ __('organization_members.member_count', ['count'=>$org->members_count]) }}</li>
                @endforeach
            </ul>
        @endif
        <footer class="border-t border-border mt-10 pt-6 flex flex-wrap gap-4">
            <a class="underline" href="{{ lroute('organizations.explore') }}">{{ __('organizations.explore') }}</a>
            <a class="underline" href="{{ lroute('organizations.terms') }}">{{ __('organizations.terms') }}</a>
        </footer>
    </div>
</section>
@endsection
