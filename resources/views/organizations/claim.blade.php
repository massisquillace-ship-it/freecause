@extends('layouts.legacy')
@section('title', __('organizations.claim_page').' — '.$org->name)
@section('content')
<section class="container py-10 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.claim_page') }}: {{ $org->name }}</h1>
    @if($org->status === 'pending')
        <p role="status">{{ __('organizations.claim_review') }}</p>
    @else
        @if($errors->any())<div role="alert" class="mb-6">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form method="post" action="{{ lroute('organizations.claim.store', ['slug'=>$org->slug]) }}" class="space-y-6">
            @csrf
            <div><label class="block mb-2" for="claim-type">{{ __('organizations.type') }}</label><select id="claim-type" name="type" class="block w-full border border-border rounded-sm p-3" required>@foreach(\App\Models\Organization::TYPES as $type)<option value="{{ $type }}" @selected(old('type', $org->type) === $type)>{{ __('organizations.'.$type) }}</option>@endforeach</select></div>
            <div><label class="block mb-2" for="claim-reference">{{ __('organizations.verification_reference') }}</label><p id="claim-reference-help" class="mb-2">{{ __('organizations.reference_help') }}</p><textarea id="claim-reference" name="verification_reference" maxlength="1000" rows="5" aria-describedby="claim-reference-help" class="w-full border border-border rounded-sm p-3" required>{{ old('verification_reference') }}</textarea></div>
            <label class="flex gap-2"><input type="checkbox" name="representation_confirmed" value="1" required @checked(old('representation_confirmed'))> {{ __('organizations.representation') }}</label>
            <label class="flex gap-2"><input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted'))> <span>{{ __('organizations.accept_terms') }} <a class="underline" href="{{ lroute('organizations.terms') }}" target="_blank" rel="noopener">{{ __('organizations.terms') }}</a></span></label>
            <x-ui.button type="submit">{{ __('organizations.claim_page') }}</x-ui.button>
        </form>
    @endif
</section>
@endsection
