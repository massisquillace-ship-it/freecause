@extends('layouts.legacy')
@section('title', __('home.hero.search_label').' | FreeCause')
@section('content')
<section class="fc-section">
    <x-ui.container>
        <h1 class="text-3xl font-semibold mb-6">{{ __('home.hero.search_label') }}</h1>
        <form method="GET" action="{{ lroute('search') }}" role="search" class="flex flex-col sm:flex-row gap-2 max-w-2xl">
            <label for="search-query" class="sr-only">{{ __('home.hero.search_label') }}</label>
            <input id="search-query" type="search" name="q" maxlength="120" value="{{ $search }}" class="fcui-input flex-1 min-w-0" required>
            <x-ui.button>{{ __('home.search_button') }}</x-ui.button>
        </form>
        <nav aria-label="{{ __('home.hero.search_label') }}" class="flex flex-wrap gap-5 mt-7 mb-6 border-b border-border pb-3">
            @foreach(['organizations','petitions'] as $choice)<a class="underline {{ $tab === $choice ? 'font-semibold text-brand' : 'text-ink' }}" href="{{ lroute('search', ['q'=>$search, 'tab'=>$choice]) }}" @if($tab === $choice) aria-current="page" @endif>{{ __('home.search_'.$choice) }} ({{ ${$choice}->count() }})</a>@endforeach
        </nav>
        @if($tab === 'organizations')
            <ul class="grid md:grid-cols-3 gap-6">@forelse($organizations as $org)<li class="bg-white rounded-lg shadow-elev-1 p-5 min-w-0"><h2 class="text-lg break-words"><a class="underline" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}">{{ $org->name }}</a></h2><p class="text-muted text-sm">{{ $org->country }} · {{ $org->status === 'verified' ? __('organizations.verified') : __('organizations.unclaimed') }}</p></li>@empty<li>{{ __('home.search_empty') }}</li>@endforelse</ul>
        @else
            <div class="grid md:grid-cols-3 gap-6">@forelse($petitions as $petition)@include('home.campaign-card', ['petition'=>$petition])@empty<p>{{ __('home.search_empty') }}</p>@endforelse</div>
        @endif
    </x-ui.container>
</section>
@endsection
