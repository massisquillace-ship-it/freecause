@php
    $badgeLocale = app()->getLocale();
    $badgeUrl = canonical_url($badgeLocale.'/org/'.rawurlencode($org->slug).'/badge.svg');
    $badgeTarget = canonical_url($badgeLocale.'/org/'.rawurlencode($org->slug)).'?source=org-badge';
    $embedHtml = '<a href="'.e($badgeTarget).'"><img src="'.e($badgeUrl).'" alt="'.e(__('organizations.badge_campaigns')).'" width="320" height="80" /></a>';
@endphp
<section class="border border-border rounded-lg p-4 mt-8">
    <h2 class="text-2xl font-bold mb-4">{{ __('organizations.embed_title') }}</h2>
    <img class="max-w-full h-auto" src="{{ lroute('organizations.badge', ['slug'=>$org->slug]) }}" width="320" height="80" alt="{{ __('organizations.badge_campaigns') }}">
    <label class="block mt-4" for="organization-embed">{{ __('organizations.embed_help') }}</label>
    <textarea id="organization-embed" class="w-full border border-border p-3 mt-2 font-mono text-sm" rows="5" readonly>{{ $embedHtml }}</textarea>
</section>
