@extends('layouts.legacy')

@php
    $pageTitle = __('sign.page_title', ['title' => $tr->title ?? __('common.petition')]);

    $h2Line1 = __('sign.h2_line1');
    $h2Line2 = __('sign.h2_line2');
    $btnText = __('sign.btn');

    $signUrl = isset($tr) && $tr ? lroute('petition.sign', ['slug' => $tr->slug, 'id' => $petition->id]) : '#';

    $signData = session('sign.'.$petition->id, []);
@endphp

@section('title', $pageTitle)

@push('head')
    <style>@view-transition { navigation: none; }</style>
@endpush

@section('content')
    <x-ui.section>
            <div class="bg-white shadow-elev-1 rounded-md p-6 md:p-12 text-center max-w-[900px] mx-auto">

                <h1 class="mb-6 text-xl md:text-2xl font-medium break-words [overflow-wrap:anywhere]">
                    {{ $h2Line1 }}<br>
                    {{ $h2Line2 }}
                </h1>

                @if (auth()->check() || ! empty($signData))
                <form action="{{ $signUrl }}" method="POST" data-sign-busy class="inline-block w-full md:w-auto">
                    @csrf
                    <x-ui.alert variant="error" class="mt-2" data-sign-network-error hidden>{{ __('sign.connection_error') }}</x-ui.alert>

                    @if ($errors->any())
                        <x-ui.alert variant="error" class="text-start mt-2">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $e)
                                    <li>{{ $e }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    @endif

                    @foreach (['name', 'email'] as $field)
                        @if (filled(data_get($signData, $field)))
                            <input type="hidden" name="{{ $field }}" value="{{ $signData[$field] }}">
                        @endif
                    @endforeach

                    <input type="hidden" name="agree1" value="{{ data_get($signData, 'agree1', 'agree') }}">
                    <input type="hidden" name="agree2" value="{{ data_get($signData, 'agree2', 'agree') }}">
                    <input type="hidden" name="agree3" value="{{ data_get($signData, 'agree3', 'agree') }}">

                    @include('petition.partials.organization-consent', ['previousOrganizationConsent'=>$signData])

                    <input type="hidden" name="comment" value="{{ data_get($signData, 'comment', __('sign.default_comment')) }}">

                    @if (! auth()->check() && \App\Support\Spam::turnstileEnabled())
                        <div class="mb-3 cf-turnstile" data-sitekey="{{ \App\Support\Settings::get('turnstile_site_key', '', 'global') }}"></div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    @endif

                    <x-ui.button type="submit" size="lg" class="max-md:hidden">
                        {{ $btnText }} <span>»</span>
                    </x-ui.button>

                    <x-ui.sticky-action :label="$btnText" />
                </form>
                @else
                <div class="text-start">
                    @include('petition.partials._sign_form', [
                        'variant' => 'stack',
                        'petition' => $petition,
                        'tr' => $tr,
                        'locale' => $locale,
                        'hasSigned' => false,
                        'stickySubmit' => true,
                    ])
                </div>
                @endif
            </div>
        </x-ui.section>

    <x-ads.slot name="rectangle" class="mb-4 text-center" />
@endsection
