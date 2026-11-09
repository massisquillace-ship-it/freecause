@props(['name' => null])
@php
    // Callers used to pin each infeed to one zone (native / rectangle / banner468),
    // which repeated the same ad every 4th row. When no name is passed the infeed
    // rotates through a mobile-safe pool instead -- Ads::renderOnce + pick() still
    // prevent the same key appearing twice on one page. banner468 is excluded: it
    // is max-md:hidden now, so it would render an empty box inside the mobile infeed.
    $rotated = $name
        ? $name
        : \App\Support\Ads::pick('infeed_mobile');
@endphp
@if ($rotated && \App\Support\Ads::canShow($rotated) && \App\Support\Ads::renderOnce($rotated))
    <div class="fc-ad-infeed lg:hidden text-center" role="complementary" aria-label="{{ __('ui.advertisement') }}">
        <x-ads.slot :name="$rotated" claimed class="mx-auto" />
    </div>
@endif