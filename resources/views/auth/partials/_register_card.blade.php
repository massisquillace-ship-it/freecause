
@php($isRegisterSubmit = old('auth_form') === 'register')
<div class="bg-surface rounded-md shadow-elev-1 p-4">
    @include('auth.partials._social_box', ['redirect' => $redirect])

    <div class="mb-4">
        <div class="font-semibold text-xl">{{ __('auth.register') }}</div>
    </div>

    @if (old('auth_form') === 'register' && $errors->any())
        <x-ui.alert variant="error" class="mb-4">
            <ul class="mb-0">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ lroute('register.post') }}">
        @csrf
        <input type="hidden" name="auth_form" value="register">
        <input type="hidden" name="redirect" value="{{ $redirect }}">

        <div class="mb-4">
            <x-ui.input
                id="register_name"
                type="text"
                name="name"
                :label="__('auth.name')"
                :value="old('name')"
                :required="true"
                :error="$isRegisterSubmit ? $errors->first('name') : null"
                autocomplete="given-name"
                class="max-md:min-h-12"
            />
        </div>

        <div class="mb-4">
            <x-ui.input
                id="register_surname"
                type="text"
                name="surname"
                :label="__('auth.surname')"
                :value="old('surname')"
                :required="true"
                :error="$isRegisterSubmit ? $errors->first('surname') : null"
                autocomplete="family-name"
                class="max-md:min-h-12"
            />
        </div>

        <div class="mb-4">
            <x-ui.input
                id="register_email"
                type="email"
                name="email"
                :label="__('auth.email')"
                :value="old('email')"
                :required="true"
                :error="$isRegisterSubmit ? $errors->first('email') : null"
                autocomplete="email"
                data-email-suggest
                class="max-md:min-h-12"
            />
        </div>

        <div class="mb-4">
            <x-ui.input
                id="register_password"
                type="password"
                name="password"
                :label="__('auth.password')"
                :required="true"
                :error="$isRegisterSubmit ? $errors->first('password') : null"
                autocomplete="new-password"
                data-strength
                data-strength-labels='["Very weak","Weak","Fair","Good","Strong"]'
                class="max-md:min-h-12"
            />
        </div>

        <div class="mb-4 text-sm">
            {{ __('auth.nickname_hint') }}
        </div>

        <div class="mb-4">
            <x-ui.input
                id="register_nickname"
                type="text"
                name="nickname"
                :label="__('sign.nickname')"
                :value="old('nickname')"
                :error="$isRegisterSubmit ? $errors->first('nickname') : null"
                class="max-md:min-h-12"
            />
        </div>

        <div class="mb-4">
            <x-ui.input
                id="register_city"
                type="text"
                name="city"
                :label="__('sign.city')"
                :value="old('city')"
                :error="$isRegisterSubmit ? $errors->first('city') : null"
                autocomplete="address-level2"
                class="max-md:min-h-12"
            />
        </div>

        <div class="block min-h-6 pl-6 mb-4">
            <input class="form-check-input" type="checkbox" name="agree_terms" id="agree_terms" {{ old('agree_terms') ? 'checked' : '' }}>
            <label class="form-check-label" for="agree_terms">
                <span class="text-brand">{{ __('auth.accept_terms') }}</span>
            </label>
        </div>

        @if (\App\Support\Spam::turnstileEnabled())
            <div class="mb-3 cf-turnstile" data-render="explicit" data-sitekey="{{ \App\Support\Settings::get('turnstile_site_key', '', 'global') }}"></div>
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=fcTurnstileOnload" async defer></script>
        @endif

        <div class="hidden md:block">
            <x-ui.button type="submit" variant="danger" class="px-6 max-md:min-h-12">{{ __('auth.submit') }}</x-ui.button>
        </div>

        <x-ui.sticky-action :label="__('auth.submit')" />
    </form>
</div>
