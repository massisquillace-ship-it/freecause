@extends('layouts.legacy')
@section('title', __('organizations.manage'))
@section('content')
<section class="container py-10 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6 break-words">{{ $org->name }}</h1>
    @if(session('success'))<p role="status" class="mb-4">{{ session('success') }}</p>@endif
    <div class="border border-border rounded-lg p-6 mb-6">
        <h2 class="text-xl font-semibold">{{ __('organizations.status') }}: {{ __('organizations.'.$org->status) }}</h2>
        @if($org->status === 'pending')<p class="mt-3">{{ __('organizations.pending_help') }}</p>@endif
        @if($org->status === 'suspended')<p class="mt-3">{{ __('organizations.suspended_help') }}</p>@endif
        @if($org->rejection_reason)<p class="mt-3 whitespace-pre-line">{{ $org->rejection_reason }}</p>@endif
        @if($org->suspension_reason)<p class="mt-3 whitespace-pre-line">{{ $org->suspension_reason === 'owner_account_deleted' ? __('organizations.owner_account_deleted') : $org->suspension_reason }}</p>@endif
        @if($org->status === 'verified')
            @if($org->founding_partner)<p class="mt-3 font-semibold">{{ __('organizations.founding_badge') }}</p>@endif
            <a class="underline block mt-3" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}">{{ __('organizations.public_profile') }}</a>
        @endif
    </div>
    <div class="flex flex-wrap gap-4 mb-6">
        @if($org->status !== 'suspended')<x-ui.button :href="lroute('organizations.edit', ['slug'=>$org->slug])">{{ __('organizations.edit') }}</x-ui.button>@endif
        @if($org->status === 'rejected')<form method="post" action="{{ lroute('organizations.resubmit', ['slug'=>$org->slug]) }}">@csrf<x-ui.button variant="secondary">{{ __('organizations.resubmit') }}</x-ui.button></form>@endif
    </div>
    @if($org->donation_url)<p class="mb-6">{{ $org->donation_url_approved_at ? __('organizations.donation_approved') : __('organizations.donation_pending') }}</p>@endif
    <h2 class="text-2xl font-bold mb-4">{{ __('organizations.campaigns') }}</h2>
    <p class="mb-3">{{ trans_choice('organizations.petition_count', (int)$org->public_petitions_count, ['count'=>(int)$org->public_petitions_count]) }} · {{ trans_choice('organizations.signature_count', (int)$org->signatures_total, ['count'=>(int)$org->signatures_total]) }}</p>
    <a class="underline" href="{{ lroute('organizations.campaigns', ['slug'=>$org->slug]) }}">{{ __('organizations.manage_campaigns') }}</a>
    <h2 class="text-2xl font-bold mt-8 mb-3">{{ __('organizations.consent_totals') }}</h2>
    <p>{{ __('organizations.consents_total', ['count'=>$consentTotal]) }} · {{ __('organizations.consents_recent', ['count'=>$consentRecent]) }}</p>
</section>
@endsection
