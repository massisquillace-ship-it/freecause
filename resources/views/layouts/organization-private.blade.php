<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') — FreeCause</title>
    @vite('resources/css/app.css')
</head>
<body class="fc-public bg-surface text-ink">
<header class="bg-white border-b border-border py-4">
    <div class="container flex items-center justify-between gap-4">
        <a href="{{ lroute('home') }}"><img src="{{ asset('legacy/images/logo7.png') }}" width="130" height="21" alt="FreeCause"></a>
        <a class="underline text-brand" href="{{ request()->routeIs('admin.*') ? route('admin.organizations') : lroute('organizations') }}">{{ __('organizations.my_organizations') }}</a>
    </div>
</header>
<main id="fc-main" class="container py-8 max-w-5xl">
    @if(session('success'))<p role="status" class="border border-border rounded-lg p-4 mb-6">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert" class="border border-danger rounded-lg p-4 mb-6"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
</body>
</html>
