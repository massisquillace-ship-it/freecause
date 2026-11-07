@if($petition->organization && $petition->organization->status === 'verified' && $petition->organization->owner_user_id)
    <div class="mt-3 text-sm">
        <p class="flex flex-wrap items-center gap-2">{{ __('organizations.launched_by') }} <a class="inline-flex items-center gap-2 underline" href="{{ lroute('organizations.show', ['slug'=>$petition->organization->slug]) }}">@if($petition->organization->logo_path)<img src="{{ Storage::disk('public')->url($petition->organization->logo_path) }}" width="24" height="24" class="size-6 object-cover rounded-sm" alt="">@else<span class="size-6 bg-surface-muted rounded-sm text-center" aria-hidden="true">{{ mb_substr($petition->organization->name,0,1) }}</span>@endif{{ $petition->organization->name }}</a> <span>✓ {{ __('organizations.verified') }}</span></p>
        <p class="mt-2">{{ __('organizations.verification_explanation') }}</p>
    </div>
@endif
@if($petition->causes->isNotEmpty())<ul class="flex flex-wrap gap-2 mt-3">@foreach($petition->causes as $cause)<li><a class="border border-border px-2 py-1 rounded-sm underline text-sm" href="{{ lroute('organizations.explore', ['cause'=>$cause->slug]) }}">{{ $cause->label() }}</a></li>@endforeach</ul>@endif
