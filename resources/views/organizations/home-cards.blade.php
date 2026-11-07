@if(isset($organizations) && $organizations->count() >= 3)
<section class="container py-10" aria-labelledby="home-organizations-title">
    <h2 id="home-organizations-title" class="text-2xl font-bold mb-6">{{ __('organizations.home_title') }}</h2>
    <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($organizations as $org)
        <li class="border border-border rounded-lg p-4 min-w-0">
            <a class="flex items-center gap-3 underline" href="{{ lroute('organizations.show', ['slug'=>$org->slug]) }}">
                @if($org->logo_path)<img src="{{ Storage::disk('public')->url($org->logo_path) }}" width="48" height="48" loading="lazy" class="size-12 object-cover rounded-sm shrink-0" alt="">@endif
                <span class="font-bold break-words">{{ $org->name }}</span>
            </a><p class="mt-3">{{ $org->localized('tagline') }}</p><p class="text-sm mt-2">{{ $org->country }}</p>
        </li>
        @endforeach
    </ul>
    <a class="underline block mt-6" href="{{ lroute('organizations.explore') }}">{{ __('organizations.see_all') }}</a>
</section>
@endif
