<link rel="stylesheet" href="{{ asset('vendor/cookieconsent/cookieconsent.css') }}?v=3.2.1">
<link rel="stylesheet" href="{{ asset('css/consent.css') }}?v=1">
<script defer src="{{ asset('js/consent-bootstrap.js') }}?v=1" data-cfasync="false"></script>
<script defer src="{{ asset('vendor/cookieconsent/cookieconsent.umd.js') }}?v=3.2.1" data-cfasync="false"></script>
<script defer src="{{ asset('js/consent.js') }}?v=2" data-fc-consent-locale="{{ app()->getLocale() }}" data-cfasync="false"></script>
@if (config('aggregate-traffic.enabled'))
<script defer src="{{ asset('js/aggregate-traffic.js') }}?v=1" data-cfasync="false"></script>
@endif
