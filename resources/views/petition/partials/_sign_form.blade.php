@php
    $variant = $variant ?? 'stack';
    $col = $variant === 'split' ? 'w-full md:w-1/2 shrink-0 px-1' : 'w-full px-1';
    $useLabels = $variant === 'split';
    $stickySubmit = $stickySubmit ?? false;
    $isAuthed = auth()->check();

    $labelClass = $useLabels ? 'block mb-1 text-xs text-ink' : 'block mb-1 text-xs text-ink md:sr-only';
    $inputClass = 'block w-full px-2 py-2 text-base md:text-sm text-ink bg-white border border-border rounded-sm appearance-none focus:border-brand focus:outline-none focus:ring-4 focus:ring-brand/25 placeholder:italic placeholder:text-muted max-md:placeholder:text-transparent max-md:min-h-12 md:min-h-0';
    $submitClass = 'fcui-btn fcui-btn--md fcui-btn--danger w-full md:w-auto max-md:min-h-12 md:min-h-0';

    $tSignedAlready = __('sign.already_signed');
    $tSignedHint = __('sign.signed_hint');
    $tBtnSign = __('sign.btn');
    $tBtnArrow = __('sign.btn_arrow');

    $phName = __('sign.ph_name');
    $phEmail = __('sign.ph_email');
    $phComment = __('sign.ph_comment');
    $defaultComment = __('sign.default_comment');

    $lblName = __('sign.lbl_name');
    $lblEmail = __('sign.lbl_email');
    $lblComment = __('sign.lbl_comment');

    $privacyHint = __('sign.privacy_hint');
    $emailWarning = __('sign.email_warning');

    $agree1Title = __('sign.agree1_title', ['terms_url' => lroute('page.show', ['slug' => 'terms-of-service'])]);
    $agree2Title = __('sign.agree2_title', ['privacy_url' => lroute('page.show', ['slug' => 'privacy-policy'])]);
    $agree3Title = __('sign.agree3_title', ['privacy_url' => lroute('page.show', ['slug' => 'privacy-policy'])]);
    $agreeYes = __('sign.agree_yes');
    $agreeNo = __('sign.agree_no');

    $signPageUrl = lroute('petition.sign.page', ['slug' => $tr->slug ?? '', 'id' => $petition->id]);
    $stateless = request()->attributes->get('stateless_render') === true;
    $isPublished = ($petition->status ?? 'published') === 'published';
@endphp

@if (! $isPublished)
    <p class="text-sm text-muted">{{ __('petition.review_required') }}</p>
@else

@if ($isAuthed)
    @if (!empty($hasSigned) && $hasSigned)

        <p class="mb-0 text-sm">
            {{ $tSignedAlready }}
        </p>
    @else
        @if (! auth()->user()->hasCompleteProfile())
            @once
                <x-ui.alert variant="warning" class="mb-3">
                    <p class="mb-2">{{ __('profile.complete_required_sign') }}</p>
                    <x-ui.button variant="secondary" size="sm" :href="lroute('profile', ['return' => request()->fullUrl()])">{{ __('profile.finish_profile') }}</x-ui.button>
                </x-ui.alert>
            @endonce
        @endif

        <form action="{{ $signPageUrl }}" method="POST" data-sign-busy data-validate data-msg-required="{{ __('common.required') }}" data-msg-email="{{ __('sign.email_warning') }}" class="fc-sign-form fc-sign-form--{{ $variant }}">
            @csrf
            <x-ui.alert variant="error" class="mt-2" data-sign-network-error hidden>{{ __('sign.connection_error') }}</x-ui.alert>

            <div class="flex flex-wrap -mx-1">

                @if ($errors->any())
                    <div class="w-full">
                        <x-ui.alert variant="error">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $e)
                                    <li>{{ $e }}</li>
                                @endforeach
                            </ul>
                        </x-ui.alert>
                    </div>
                @endif

                <div class="w-full mb-3">
                    <label class="{{ $labelClass }}" for="sign_comment_auth_{{ $variant }}">{{ $lblComment }}</label>
                    <input class="{{ $inputClass }}" type="text" name="comment" id="sign_comment_auth_{{ $variant }}" data-maxcount="500" @if (!$useLabels) placeholder="{{ $phComment }}" @endif
                        value="{{ old('comment', $defaultComment) }}" required>
                    <span data-error-for="comment" hidden class="text-sm text-brand mt-1 block"></span>
                </div>

                <div class="w-full md:w-auto mb-3">
                    <div class="font-bold">
                        {!! $agree1Title !!}
                    </div>
                    <div class="mt-2 flex flex-col gap-1">
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree1" value="agree"
                                {{ old('agree1', 'agree') === 'agree' ? 'checked' : '' }}>
                            {{ $agreeYes }}
                        </label>
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree1" value="no"
                                {{ old('agree1') === 'no' ? 'checked' : '' }}>
                            {{ $agreeNo }}
                        </label>
                    </div>
                </div>

                <div class="w-full md:w-auto mb-3">
                    <div class="font-bold">
                        {!! $agree2Title !!}
                    </div>
                    <div class="mt-2 flex flex-col gap-1">
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree2" value="agree"
                                {{ old('agree2', 'agree') === 'agree' ? 'checked' : '' }}>
                            {{ $agreeYes }}
                        </label>
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree2" value="no"
                                {{ old('agree2') === 'no' ? 'checked' : '' }}>
                            {{ $agreeNo }}
                        </label>
                    </div>
                </div>

                <div class="w-full md:w-auto mb-3">
                    <div class="font-bold">
                        {!! $agree3Title !!}
                    </div>
                    <div class="mt-2 flex flex-col gap-1">
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree3" value="agree"
                                {{ old('agree3', 'agree') === 'agree' ? 'checked' : '' }}>
                            {{ $agreeYes }}
                        </label>
                        <label class="flex items-center gap-2 text-sm min-h-12">
                            <input class="form-check-input shrink-0 float-none ml-0 mt-0" type="radio" name="agree3" value="no"
                                {{ old('agree3') === 'no' ? 'checked' : '' }}>
                            {{ $agreeNo }}
                        </label>
                    </div>
                </div>

                @include('petition.partials.organization-consent')

                <p class="text-sm text-muted mb-3">
                    <a class="text-brand underline" href="{{ lroute('page.show', ['slug' => 'ethical-code']) }}">{{ __('sign.guidelines_link') }}</a>
                </p>

                <div class="w-full">
                    <button class="{{ $submitClass }}@if ($stickySubmit) max-md:hidden @endif" type="submit">{{ $tBtnSign }}</button>
                </div>

            </div>

            @if ($stickySubmit)
                <x-ui.sticky-action :label="$tBtnSign" />
            @endif
        </form>

    @endif
@else
    @if ($stateless)
        <x-ui.button variant="danger" :href="$signPageUrl" class="w-full" data-sign-cta data-cached="1">{{ $tBtnSign }}</x-ui.button>
    @else
    <form action="{{ $signPageUrl }}" method="POST" data-sign-busy data-validate data-msg-required="{{ __('common.required') }}" data-msg-email="{{ __('sign.email_warning') }}" class="fc-sign-form fc-sign-form--{{ $variant }}">
        @csrf
        <x-ui.alert variant="error" class="mt-2" data-sign-network-error hidden>{{ __('sign.connection_error') }}</x-ui.alert>

        <div class="flex flex-wrap -mx-1">

            @if ($errors->any())
                <div class="w-full">
                    <x-ui.alert variant="error">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $e)
                                <li>{{ $e }}</li>
                            @endforeach

                            @if (session('login_url'))
                                <li><a class="text-brand" href="{{ session('login_url') }}">{{ __('auth.go_to_login') }}</a></li>
                            @endif
                        </ul>
                    </x-ui.alert>
                </div>
            @endif

            <div class="{{ $col }} mb-3">
                <label class="{{ $labelClass }}" for="sign_name_{{ $variant }}">{{ $lblName }}</label>
                <input class="{{ $inputClass }}" type="text" name="name" id="sign_name_{{ $variant }}" @if (!$useLabels) placeholder="{{ $phName }}" @endif value="{{ old('name') }}" required>
                <span data-error-for="name" hidden class="text-sm text-brand mt-1 block"></span>
            </div>

            <div class="{{ $col }} mb-3">
                <label class="{{ $labelClass }}" for="sign_email_{{ $variant }}">{{ $lblEmail }}</label>
                <input class="{{ $inputClass }}" type="email" name="email" id="sign_email_{{ $variant }}" data-email-suggest @if (!$useLabels) placeholder="{{ $phEmail }}" @endif value="{{ old('email') }}" required aria-required="true">
                <span data-error-for="email" hidden class="text-sm text-brand mt-1 block"></span>
            </div>

            @if ($variant === 'split')
                <div class="w-full text-sm">
                    {{ $privacyHint }}
                </div>
            @endif

            <div class="fc-sign-advanced hidden">
                <div class="w-full mb-3">
                    <label class="{{ $labelClass }}" for="sign_comment_guest_{{ $variant }}">{{ $lblComment }}</label>
                    <input class="{{ $inputClass }}" type="text" name="comment" id="sign_comment_guest_{{ $variant }}" data-maxcount="500" @if (!$useLabels) placeholder="{{ $phComment }}" @endif
                        value="{{ old('comment', $defaultComment) }}" required>
                    <span data-error-for="comment" hidden class="text-sm text-brand mt-1 block"></span>
                </div>
            </div>

            <div class="w-full">
                @if ($variant === 'split')
                    <p class="mt-2 text-sm leading-[1.5] mb-3">
                        {{ $emailWarning }}
                    </p>
                @endif

                <div class="p-4 rounded-sm bg-surface-2 border border-border mb-3">
                    <div class="flex items-start gap-2 mb-2">
                        <input class="form-check-input shrink-0 float-none ml-0 mt-0.5" type="checkbox" id="agree1_{{ $variant }}" name="agree1" value="1" {{ old('agree1') ? 'checked' : '' }}>
                        <label class="form-check-label text-sm leading-snug" for="agree1_{{ $variant }}">{!! $agree1Title !!}</label>
                    </div>
                    <div class="flex items-start gap-2 mb-2">
                        <input class="form-check-input shrink-0 float-none ml-0 mt-0.5" type="checkbox" id="agree2_{{ $variant }}" name="agree2" value="1" {{ old('agree2') ? 'checked' : '' }}>
                        <label class="form-check-label text-sm leading-snug" for="agree2_{{ $variant }}">{!! $agree2Title !!}</label>
                    </div>
                    <div class="flex items-start gap-2">
                        <input class="form-check-input shrink-0 float-none ml-0 mt-0.5" type="checkbox" id="agree3_{{ $variant }}" name="agree3" value="1" {{ old('agree3') ? 'checked' : '' }}>
                        <label class="form-check-label text-sm leading-snug" for="agree3_{{ $variant }}">{!! $agree3Title !!}</label>
                    </div>
                </div>

                @include('petition.partials.organization-consent')

                <button class="{{ $submitClass }}@if ($stickySubmit) max-md:hidden @endif" type="submit">{{ $tBtnSign }}</button>
            </div>
        </div>

        @if ($stickySubmit)
            <x-ui.sticky-action :label="$tBtnSign" />
        @endif
    </form>
    @endif
@endif
@endif
