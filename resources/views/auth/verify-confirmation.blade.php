@extends('layouts.legacy')

@section('title', __('auth.verify_confirm_title'))

@section('content')
    <section class="fc-section">
        <x-ui.container>
            <div class="flex flex-wrap -mx-3 justify-center">
                <div class="w-full shrink-0 grow-0 px-3 md:w-2/3 lg:w-1/2">
                    <div class="relative flex flex-col min-w-0 bg-surface bg-clip-border rounded-lg shadow-elev-1">
                        <div class="grow shrink basis-auto p-6 md:p-12 text-center">
                            <h1 class="mb-4 text-2xl font-medium">{{ __('auth.verify_confirm_heading') }}</h1>

                            @if ($expired)
                                <p class="text-muted mb-6">{{ __('auth.verify_confirm_expired') }}</p>
                                <x-ui.button variant="danger" :href="lroute('verification.resend.form')" class="w-full max-md:min-h-12">
                                    {{ __('auth.resend_verification') }}
                                </x-ui.button>
                            @else
                                <p class="text-muted mb-6">{{ __('auth.verify_confirm_body') }}</p>
                                <form method="POST" action="{{ lroute('verify.account.confirm', ['token' => $token]) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="danger" class="w-full max-md:min-h-12">
                                        {{ __('auth.verify_confirm_button') }}
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.container>
    </section>
@endsection
