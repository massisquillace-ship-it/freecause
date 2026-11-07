@extends('admin.layouts.app')
@section('title', __('organizations.title'))
@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('organizations.title') }}</h1>
<form method="get" class="flex flex-wrap gap-3 mb-6">
    <label>{{ __('organizations.search') }}<input class="fc-filter-field block" type="search" name="q" maxlength="120" value="{{ request('q') }}"></label>
    <label>{{ __('organizations.status') }}<select class="fc-filter-field block" name="status"><option value="">{{ __('organizations.all_states') }}</option>@foreach(\App\Models\Organization::STATES as $state)<option value="{{ $state }}" @selected(request('status') === $state)>{{ __('organizations.'.$state) }}</option>@endforeach</select></label>
    <x-blatui.button type="submit">{{ __('organizations.filter') }}</x-blatui.button>
</form>
<div class="space-y-3">
    @foreach($organizations as $org)
        <x-blatui.card class="p-4"><a class="underline font-semibold" href="{{ route('admin.organizations.show', ['organization'=>$org->id]) }}">{{ $org->name }}</a> — {{ __('organizations.'.$org->status) }}<p>{{ __('organizations.owner') }}: {{ $org->owner?->email ?? '—' }}</p></x-blatui.card>
    @endforeach
</div>
<div class="mt-6">{{ $organizations->links() }}</div>
@endsection
