<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>{{ __('organizations.revoke_title') }} — FreeCause</title>@vite('resources/css/app.css')</head><body>
<section class="container py-12 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.revoke_title') }}</h1>
    @if($revoked)<p role="status">{{ __('organizations.revoked') }}</p>@else<p class="mb-6">{{ __('organizations.revoke_help') }}</p><form method="post" action="{{ $action }}">@csrf<x-ui.button>{{ __('organizations.revoke_confirm') }}</x-ui.button></form>@endif
</section>
</body></html>
