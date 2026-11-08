@extends('layouts.legacy')
@section('title', $org->name.' — FreeCause')
@section('canonical', $profileUrl)
@section('og_url', $profileUrl)
@section('content')
<article class="container py-10 max-w-4xl">
    <h1 class="text-3xl font-bold mb-4">{{ $org->name }}</h1>
    <p class="mb-3">{{ \App\Support\Countries::all()[$org->country] ?? $org->country }}</p>
    <p class="font-semibold mb-4">{{ __('organizations.unclaimed') }}</p>
    <p class="mb-4">{{ __('organizations.unclaimed_disclaimer') }} <a class="underline" href="mailto:{{ \App\Models\Organization::supportEmail() }}?{{ http_build_query(['subject'=>'Remove unclaimed profile', 'body'=>$profileUrl], '', '&', PHP_QUERY_RFC3986) }}">{{ __('organizations.report') }}</a></p>
    <x-ui.button :href="lroute('organizations.claim', ['slug'=>$org->slug])">{{ __('organizations.claim_this_organization') }}</x-ui.button>
    <ul class="flex flex-wrap gap-2 my-6">@foreach($org->causes as $cause)<li class="border border-border rounded-sm px-3 py-1">{{ $cause->label() }}</li>@endforeach</ul>
    <p class="mb-3">{{ trans_choice('organizations.signature_count', (int)$org->signatures_total, ['count'=>(int)$org->signatures_total]) }} · {{ trans_choice('organizations.victory_count', (int)$org->victories_count, ['count'=>(int)$org->victories_count]) }}</p>
    <h2 class="text-2xl font-bold mb-4">{{ __('organizations.campaigns') }}</h2>
    @include('organizations._campaigns')
</article>
@endsection
