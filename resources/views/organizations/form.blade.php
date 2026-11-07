@extends('layouts.legacy')
@section('title', $org->exists ? __('organizations.edit') : __('organizations.apply'))
@section('content')
<section class="container py-10 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">{{ $org->exists ? __('organizations.edit') : __('organizations.apply') }}</h1>
    @if($errors->any())
        <div role="alert" class="border border-border p-4 mb-6"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if($org->verified_at)<p class="mb-6">{{ __('organizations.identity_locked') }}</p>@endif
    <form method="post" enctype="multipart/form-data" action="{{ $org->exists ? lroute('organizations.update', ['slug' => $org->slug]) : lroute('organizations.store') }}" class="space-y-6">
        @csrf
        @foreach(['name', 'legal_name', 'slug', 'territory', 'tax_code', 'website', 'donation_url', 'email_public'] as $field)
            <div>
                <label class="block font-semibold mb-2" for="org-{{ $field }}">{{ __('organizations.'.$field) }}</label>
                <input class="w-full border border-border rounded-sm p-3" id="org-{{ $field }}" name="{{ $field }}" type="{{ in_array($field, ['website','donation_url']) ? 'url' : ($field === 'email_public' ? 'email' : 'text') }}" value="{{ old($field, $org->$field) }}" maxlength="{{ ['name'=>120,'legal_name'=>200,'slug'=>80,'territory'=>160,'tax_code'=>16,'email_public'=>254][$field] ?? 2048 }}" @required($field === 'name') @disabled($org->verified_at && in_array($field, \App\Models\Organization::IDENTITY_FIELDS))>
            </div>
        @endforeach
        <div>
            <label class="block font-semibold mb-2" for="org-type">{{ __('organizations.type') }}</label>
            <select id="org-type" name="type" class="w-full border border-border rounded-sm p-3" required @disabled($org->verified_at)>
                @foreach(\App\Models\Organization::TYPES as $type)<option value="{{ $type }}" @selected(old('type', $org->type) === $type)>{{ __('organizations.'.$type) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block font-semibold mb-2" for="org-country">{{ __('organizations.country') }}</label>
            <select id="org-country" name="country" class="w-full border border-border rounded-sm p-3" required @disabled($org->verified_at)>
                @foreach($countries as $code => $name)<option value="{{ $code }}" @selected(old('country', $org->country) === $code)>{{ $name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block font-semibold mb-2" for="org-reference">{{ __('organizations.verification_reference') }}</label>
            <p id="reference-help" class="mb-2">{{ __('organizations.reference_help') }}</p>
            <textarea id="org-reference" name="verification_reference" maxlength="1000" rows="4" required aria-describedby="reference-help" class="w-full border border-border rounded-sm p-3">{{ old('verification_reference', $org->verification_reference) }}</textarea>
        </div>
        <fieldset>
            <legend class="font-semibold mb-3">{{ __('organizations.causes') }}</legend>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach($causes as $cause)
                    <label class="flex gap-2"><input type="checkbox" name="causes[]" value="{{ $cause->id }}" @checked(in_array($cause->id, old('causes', $org->exists ? $org->causes->modelKeys() : [])))> {{ $cause->label() }}</label>
                @endforeach
            </div>
        </fieldset>
        @foreach($languages as $code => $name)
            <details class="border border-border p-4 rounded-lg" @if($code === app()->getLocale()) open @endif>
                <summary class="font-semibold cursor-pointer">{{ $name }}</summary>
                @foreach(['tagline'=>200, 'description'=>5000] as $field => $limit)
                    <label for="org-{{ $field }}-{{ $code }}" class="block mt-4 mb-2">{{ __('organizations.'.$field) }} — {{ $name }}</label>
                    <textarea class="w-full border border-border rounded-sm p-3" id="org-{{ $field }}-{{ $code }}" name="{{ $field }}[{{ $code }}]" rows="{{ $field === 'tagline' ? 2 : 5 }}" maxlength="{{ $limit }}">{{ old($field.'.'.$code, $org->$field[$code] ?? '') }}</textarea>
                @endforeach
            </details>
        @endforeach
        @foreach(array_keys(\App\Rules\OrganizationUrl::SOCIAL_HOSTS) as $social)
            <div><label class="block mb-2" for="org-social-{{ $social }}">{{ ucfirst($social) }}</label><input class="w-full border border-border rounded-sm p-3" type="url" id="org-social-{{ $social }}" name="social_links[{{ $social }}]" maxlength="2048" value="{{ old('social_links.'.$social, $org->social_links[$social] ?? '') }}"></div>
        @endforeach
        <p>{{ __('organizations.image_help') }}</p>
        @foreach(['logo', 'cover'] as $kind)
            <div>
                <label class="block font-semibold mb-2" for="org-{{ $kind }}">{{ __('organizations.'.$kind) }}</label>
                @if($org->{$kind.'_path'})
                    <img src="{{ Storage::disk('public')->url($org->{$kind.'_path'}) }}" alt="{{ __('organizations.'.$kind) }}" class="max-w-full max-h-48 object-contain mb-3">
                    <label class="block mb-3"><input type="checkbox" name="remove_{{ $kind }}" value="1"> {{ __('organizations.remove') }}</label>
                @endif
                <input id="org-{{ $kind }}" type="file" name="{{ $kind }}" accept="image/jpeg,image/png,image/webp" class="max-w-full">
            </div>
        @endforeach
        <div>
            <label class="block font-semibold mb-2" for="org-focal">{{ __('organizations.cover_focal_point') }}</label>
            <select id="org-focal" name="cover_focal_point" class="border border-border rounded-sm p-3">
                @foreach(['top','center','bottom'] as $focal)<option value="{{ $focal }}" @selected(old('cover_focal_point', $org->cover_focal_point) === $focal)>{{ __('organizations.'.$focal) }}</option>@endforeach
            </select>
            <p class="mt-2">{{ __('organizations.focal_upload_required') }}</p>
        </div>
        @unless($org->exists)
            <label class="flex gap-2"><input type="checkbox" name="representation_confirmed" value="1" required> {{ __('organizations.representation') }}</label>
            <label class="flex gap-2"><input type="checkbox" name="terms_accepted" value="1" required> <span>{{ __('organizations.accept_terms') }} <a class="underline" href="{{ lroute('organizations.terms') }}" target="_blank" rel="noopener">{{ __('organizations.terms') }}</a></span></label>
        @endunless
        <x-ui.button>{{ __('organizations.save') }}</x-ui.button>
    </form>
</section>
@endsection
