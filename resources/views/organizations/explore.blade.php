@extends('layouts.legacy')
@section('title', __('organizations.explore'))
@section('content')
<section class="container py-10 max-w-5xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.explore') }}</h1>
    <form method="get" class="grid sm:grid-cols-2 gap-4 mb-8">
        <label>{{ __('organizations.search') }}<input class="block w-full border border-border rounded-sm p-3" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120"></label>
        <label>{{ __('organizations.cause_filter') }}<select class="block w-full border border-border rounded-sm p-3" name="cause" aria-label="{{ __('organizations.cause_filter') }}"><option value="">{{ __('organizations.all_causes') }}</option>@foreach($causes as $cause)<option value="{{ $cause->slug }}" @selected(($filters['cause'] ?? '') === $cause->slug)>{{ $cause->label() }}</option>@endforeach</select></label>
        <label>{{ __('organizations.country') }}<select class="block w-full border border-border rounded-sm p-3" name="country" aria-label="{{ __('organizations.country') }}"><option value="">{{ __('organizations.all_countries') }}</option>@foreach($countries as $code => $name)<option value="{{ $code }}" @selected(($filters['country'] ?? '') === $code)>{{ $name }}</option>@endforeach</select></label>
        <label>{{ __('organizations.sort') }}<select class="block w-full border border-border rounded-sm p-3" name="sort" aria-label="{{ __('organizations.sort') }}">@foreach(['name','signatures','newest'] as $sort)<option value="{{ $sort }}" @selected(($filters['sort'] ?? 'name') === $sort)>{{ __('organizations.sort_'.$sort) }}</option>@endforeach</select></label>
        <x-ui.button>{{ __('organizations.filter') }}</x-ui.button>
    </form>
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
    @forelse($organizations as $org)
        <li class="border border-border rounded-lg p-5 min-w-0">
            <a class="flex gap-3 items-center underline" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}">
                @if($org->logo_path)<img src="{{ Storage::disk('public')->url($org->logo_path) }}" width="48" height="48" loading="lazy" class="size-12 object-cover rounded-sm shrink-0" alt="">@else<span class="size-12 flex items-center justify-center bg-surface-muted rounded-sm shrink-0" aria-hidden="true">{{ mb_substr($org->name,0,1) }}</span>@endif
                <h2 class="text-xl font-bold break-words">{{ $org->name }}</h2>
            </a>
            <p class="mt-3">{{ $org->localized('tagline') }}</p><p class="mt-3">{{ $countries[$org->country] ?? $org->country }}</p>
            <ul class="flex flex-wrap gap-2 mt-3">@foreach($org->causes as $cause)<li class="text-sm border border-border px-2 py-1 rounded-sm">{{ $cause->label() }}</li>@endforeach</ul>
        </li>
    @empty<li>{{ __('organizations.no_results') }}</li>@endforelse
    </ul>
    <div class="mt-8">{{ $organizations->links() }}</div>
</section>
@endsection
