<ul class="space-y-4">
@forelse($campaigns as $campaign)
    @php($translation = $campaign->translationOrFallback(app()->getLocale(), 'en'))
    <li class="border border-border rounded-lg p-4">
        @if($translation && $campaign->status === 'published' && $campaign->is_active)<a class="underline font-semibold" href="{{ lroute('petition.show', ['locale'=>$translation->locale, 'slug'=>$translation->slug, 'id'=>$campaign->id]) }}">{{ $translation->title }}</a>@else<p>{{ $translation?->title ?? __('organizations.campaign_unavailable') }}</p>@endif
        @include('petition.partials.outcome', ['petition'=>$campaign])
        <p class="mt-2">{{ trans_choice('organizations.signature_count', (int)$campaign->signature_count, ['count'=>(int)$campaign->signature_count]) }}</p>
        @if(($management ?? false) && (int)$campaign->user_id === (int)auth()->id())
            <a class="underline block mt-2" href="{{ lroute('petition.outcome', ['locale'=>$translation?->locale ?? app()->getLocale(), 'slug'=>$translation?->slug ?? 'petition','id'=>$campaign->id]) }}">{{ __('organizations.update_outcome') }}</a>
            @if($campaign->is_active && in_array($campaign->status, ['draft','published'], true) && !$campaign->has_external_signatures)<a class="underline block mt-2" href="{{ lroute('petition.edit', ['locale'=>$translation?->locale ?? app()->getLocale(), 'slug'=>$translation?->slug ?? 'petition', 'id'=>$campaign->id]) }}">{{ __('organizations.edit_campaign') }}</a>@endif
            @if($org->status === 'verified')<form method="post" action="{{ lroute('organizations.detach', ['slug'=>$org->slug,'petition'=>$campaign->id]) }}" class="mt-3">@csrf<label class="block"><input type="checkbox" name="confirm" value="1" required> {{ __('organizations.detach_confirm') }}</label><x-ui.button variant="secondary" class="mt-2">{{ __('organizations.detach') }}</x-ui.button></form>@endif
        @endif
    </li>
@empty<li>{{ __('organizations.empty_campaigns') }}</li>@endforelse
</ul>
<div class="mt-6">{{ $campaigns->links() }}</div>
