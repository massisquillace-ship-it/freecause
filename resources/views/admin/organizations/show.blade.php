@extends('admin.layouts.app')
@section('title', $org->name)
@section('content')
<h1 class="text-2xl font-bold mb-6 break-words">{{ $org->name }}</h1>
@if(session('success'))<p role="status" class="mb-4">{{ session('success') }}</p>@endif
@if($errors->any())<div role="alert" class="mb-4">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<x-blatui.card class="p-6 mb-6 space-y-3">
    <p>{{ __('organizations.status') }}: {{ __('organizations.'.$org->status) }}</p>
    <p>{{ __('organizations.owner') }}: #{{ $org->owner_user_id ?? '—' }} {{ $org->owner?->email }}</p>
    <p>{{ __('organizations.type') }}: {{ __('organizations.'.$org->type) }} · {{ $org->country }}</p>
    <p>{{ __('organizations.legal_name') }}: {{ $org->legal_name }}</p>
    <p class="whitespace-pre-line break-words">{{ __('organizations.verification_reference') }}: {{ $org->verification_reference }}</p>
    <p class="whitespace-pre-line break-words">{{ __('organizations.review_note') }}: {{ $org->verification_note }}</p>
    <p>{{ __('organizations.terms_accepted') }}: {{ $org->terms_accepted_at }} · {{ $org->terms_version }}</p>
    <p>{{ __('organizations.campaigns') }}: 0</p>
    <p>{{ __('organizations.donation_url') }}: <span class="break-all">{{ $org->donation_url ?: '—' }}</span></p>
    <p>{{ $org->donation_url_approved_at ? __('organizations.donation_approved') : __('organizations.donation_pending') }}</p>
    @if($org->status === 'verified')<a class="underline" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}" target="_blank" rel="noopener">{{ __('organizations.public_profile') }}</a>@endif
</x-blatui.card>
@if(admin_can('organizations', 'edit'))
    <x-blatui.card class="p-6 mb-6">
        <h2 class="font-semibold text-xl mb-4">{{ __('organizations.status') }}</h2>
        @php($actions = $org->owner_user_id ? match($org->status) { 'pending'=>['verify','reject'], 'rejected'=>['verify'], 'verified'=>['suspend'], 'suspended'=>['restore'], default=>[] } : [])
        @foreach($actions as $action)
            <form method="post" action="{{ route('admin.organizations.action', ['organization'=>$org->id, 'action'=>$action]) }}" class="border-t border-border pt-4 mt-4 space-y-3" data-turbo="false">
                @csrf
                <label class="block" for="review-{{ $action }}">{{ __('organizations.review_note') }}</label>
                <textarea id="review-{{ $action }}" class="w-full border border-border p-3" name="reason" rows="3" maxlength="3000" required></textarea>
                <label class="flex gap-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.confirm') }}</label>
                <x-blatui.button type="submit">{{ __('organizations.'.$action) }}</x-blatui.button>
            </form>
        @endforeach
    </x-blatui.card>
    <x-blatui.card class="p-6 mb-6 space-y-4">
        <form method="post" action="{{ route('admin.organizations.action', ['organization'=>$org->id, 'action'=>'founding']) }}" class="space-y-3" data-turbo="false">
            @csrf<p>{{ __('organizations.founding_badge') }}: {{ $org->founding_partner ? '✓' : '—' }}</p>
            <label class="flex gap-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.confirm') }}</label>
            <x-blatui.button type="submit">{{ __('organizations.toggle_founding') }}</x-blatui.button>
        </form>
        @if($org->status === 'verified' && $org->donation_url)
            <form method="post" action="{{ route('admin.organizations.action', ['organization'=>$org->id, 'action'=>'donation']) }}" class="space-y-3 border-t border-border pt-4" data-turbo="false">
                @csrf<input type="hidden" name="reviewed_donation_url" value="{{ $org->donation_url }}">
                <p class="break-all">{{ $org->donation_url }}</p><p>{{ __('organizations.donation_disclosure') }}</p>
                <label class="flex gap-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.confirm') }}</label>
                <x-blatui.button type="submit">{{ __('organizations.approve_donation') }}</x-blatui.button>
            </form>
        @endif
    </x-blatui.card>
    <x-blatui.card class="p-6 mb-6">
        <h2 class="font-semibold text-xl mb-4">{{ __('organizations.reassign') }}</h2>
        <p class="mb-4">{{ __('organizations.reassign_warning') }}</p>
        <form method="post" action="{{ route('admin.organizations.action', ['organization'=>$org->id, 'action'=>'reassign']) }}" class="space-y-3" data-turbo="false">
            @csrf<label class="block" for="owner-id">{{ __('organizations.owner_id') }}</label><input class="border border-border p-3" id="owner-id" type="number" min="1" name="owner_user_id" required>
            <label class="flex gap-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.confirm') }}</label>
            <x-blatui.button type="submit">{{ __('organizations.reassign') }}</x-blatui.button>
        </form>
    </x-blatui.card>
    <x-blatui.card class="p-6 mb-6">
        <h2 class="font-semibold text-xl mb-4">{{ __('organizations.identity') }}</h2>
        <form method="post" action="{{ route('admin.organizations.action', ['organization'=>$org->id, 'action'=>'identity']) }}" class="space-y-3" data-turbo="false">
            @csrf
            @foreach(['name'=>120, 'legal_name'=>200] as $field=>$max)<label class="block" for="admin-{{ $field }}">{{ __('organizations.'.$field) }}</label><input class="w-full border border-border p-3" id="admin-{{ $field }}" name="{{ $field }}" value="{{ $org->$field }}" maxlength="{{ $max }}" @required($field === 'name')>@endforeach
            <label class="block" for="admin-type">{{ __('organizations.type') }}</label><select id="admin-type" name="type" class="border border-border p-3">@foreach(\App\Models\Organization::TYPES as $type)<option value="{{ $type }}" @selected($org->type === $type)>{{ __('organizations.'.$type) }}</option>@endforeach</select>
            <label class="block" for="admin-country">{{ __('organizations.country') }}</label><select id="admin-country" name="country" class="border border-border p-3">@foreach(\App\Support\Countries::all() as $code=>$country)<option value="{{ $code }}" @selected($org->country === $code)>{{ $country }}</option>@endforeach</select>
            <label class="flex gap-2"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.confirm') }}</label>
            <x-blatui.button type="submit">{{ __('organizations.save') }}</x-blatui.button>
        </form>
    </x-blatui.card>
@endif
<h2 class="text-xl font-semibold mb-4">{{ __('organizations.history') }}</h2>
<ul class="space-y-2">@foreach($org->events as $event)<li>{{ $event->created_at }} · {{ $event->event }} · #{{ $event->actor_user_id ?? '—' }} · {{ json_encode($event->details) }}</li>@endforeach</ul>
@endsection
