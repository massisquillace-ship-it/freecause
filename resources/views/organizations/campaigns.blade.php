@extends('layouts.legacy')
@section('title', __('organizations.campaigns'))
@section('content')
<section class="container py-10 max-w-3xl">
    <a class="underline" href="{{ lroute('organizations.manage', ['slug'=>$org->slug]) }}">{{ __('organizations.manage') }}</a>
    <h1 class="text-3xl font-bold my-6">{{ $org->name }} — {{ __('organizations.campaigns') }}</h1>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    @include('organizations._campaigns', ['management'=>true])
    @if($org->status === 'verified')
        <h2 class="text-2xl font-bold mt-8 mb-4">{{ __('organizations.attach') }}</h2>
        <p class="mb-4">{{ __('organizations.attribution_help') }}</p>
        <ul class="space-y-4">@foreach($available as $campaign)
            <li class="border border-border rounded-lg p-4"><p>{{ $campaign->translationOrFallback(app()->getLocale(),'en')?->title }}</p><form method="post" action="{{ lroute('organizations.attach', ['slug'=>$org->slug]) }}">@csrf<input type="hidden" name="petition_id" value="{{ $campaign->id }}"><label class="block my-3"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.attach_confirm') }}</label><x-ui.button variant="secondary">{{ __('organizations.attach') }}</x-ui.button></form></li>
        @endforeach</ul><div class="mt-6">{{ $available->appends(request()->except('available_page'))->links() }}</div>
    @endif
</section>
@endsection
