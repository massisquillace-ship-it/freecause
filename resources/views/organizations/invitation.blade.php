@extends('layouts.organization-private')
@section('title', __('organization_members.invitation_title'))
@section('content')
<h1 class="text-3xl font-bold mb-6">{{ __('organization_members.invitation_title') }}</h1>
@if(!$invitation)
    <p>{{ __('organization_members.invalid_invitation') }}</p>
@else
    <p class="mb-4 break-words">{{ __('organization_members.invitation_to', ['name'=>$invitation->organization->name, 'email'=>$invitation->email, 'role'=>__('organization_members.'.$invitation->role)]) }}</p>
    <a class="underline block mb-4" href="{{ lroute('organizations.terms') }}">{{ __('organizations.terms') }}</a>
    <form method="post" action="{{ request()->fullUrl() }}" class="space-y-5">@csrf
        @if(mb_strtolower(trim(auth()->user()->email)) !== $invitation->email)
            <p class="break-words">{{ __('organization_members.email_mismatch', ['email'=>auth()->user()->email]) }}</p>
            <label class="block"><input type="checkbox" name="confirm_email" value="1" required> {{ __('organization_members.mismatch_confirm') }}</label>
        @endif
        <label class="block"><input type="checkbox" name="confirm" value="1" required> {{ __('organization_members.confirm_accept') }}</label>
        <x-ui.button>{{ __('organization_members.accept') }}</x-ui.button>
    </form>
@endif
@endsection
