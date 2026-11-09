@php
    $publicOrg = $petition->organization;
    $publicOrg = $publicOrg && (($publicOrg->status === 'verified' && $publicOrg->owner_user_id) || ($publicOrg->status === 'unclaimed' && !$publicOrg->owner_user_id)) ? $publicOrg : null;
    $author = $petition->user;
    $authorName = !$author ? __('home.anonymous_author') : ($author->identify_mode === 'nick'
        ? ($author->nickname ?: __('home.anonymous_author'))
        : trim(($author->first_name ?: \Illuminate\Support\Str::before($author->name, ' ')).($author->last_name ? ' '.mb_substr($author->last_name, 0, 1).'.' : '')));
@endphp
<p class="flex items-center gap-2 text-sm text-muted min-w-0 flex-wrap">
    @if($publicOrg)
        @if(!($compact ?? false))
        @if($publicOrg->status === 'verified' && $publicOrg->logo_path)<img src="{{ Storage::disk('public')->url($publicOrg->logo_path) }}" width="24" height="24" loading="lazy" class="size-6 rounded-sm object-cover shrink-0" alt="">@else<x-lucide-building class="size-6 shrink-0" aria-hidden="true" />@endif
        @endif
        <span class="inline-flex items-center gap-1 min-w-0 flex-1">
        <a href="{{ lroute('organizations.show', ['slug'=>$publicOrg->slug]) }}" class="text-muted break-words hover:underline min-w-0">{{ $publicOrg->name }}</a>
        @if($publicOrg->status === 'verified')
            <span class="inline-flex text-info shrink-0" role="img" aria-label="{{ __('home.organization_verified') }}" title="{{ __('home.organization_verified') }}" data-organization-verified><x-lucide-badge-check class="size-4" aria-hidden="true" /></span>
        @else
            <span class="text-xs text-muted shrink-0">{{ __('home.status_unclaimed') }}</span>
        @endif
        </span>
    @else
        @if(!($compact ?? false))<x-lucide-circle-user-round class="size-6 shrink-0" aria-hidden="true" />@endif<span class="break-words">{{ __('home.started_by', ['name'=>$authorName]) }}</span>
    @endif
    @if(isset($date))<span>· {{ $date }}</span>@endif
</p>
