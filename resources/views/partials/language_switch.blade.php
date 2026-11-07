@php
    use App\Models\Language;

    $languages = cache()->remember('active_languages_full', 60, fn() => Language::where('is_active', 1)->orderByDesc('is_default')->get(),);
    $locale = app()->getLocale();
    $current = $languages->firstWhere('code', $locale) ?? $languages->first();
    $mobile = $mobile ?? false;
@endphp

<details class="relative group">
    <summary class="list-none cursor-pointer flex items-center gap-1.5 px-2 py-1 min-h-11 rounded-full border border-border bg-white hover:bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand">
        @if ($current?->flag)
            <img class="block w-5 h-5 rounded-full" fetchpriority="low" decoding="async" src="{{ asset($current->flag) }}" alt="">
        @endif
        <span class="text-xs font-medium text-ink">{{ strtoupper($current->code ?? '') }}</span>
        <x-lucide-chevron-down class="size-3 text-muted" aria-hidden="true" />
    </summary>

    <div class="absolute end-0 top-full mt-2 {{ $mobile ? 'w-64 max-w-[calc(100vw-5rem)]' : 'w-max min-w-64 max-w-[calc(100vw-2rem)]' }} max-h-[70vh] overflow-y-auto rounded-lg shadow-elev-2 bg-surface border border-border z-sticky p-2">
        <div class="flex {{ $mobile ? 'flex-col gap-1' : 'flex-wrap gap-2' }}">
                @foreach ($languages as $lang)
                    <a class="flex min-w-0 max-w-full items-center gap-2 px-2 py-1 min-h-11 rounded-lg bg-white border border-border text-sm text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand {{ $lang->code === $locale ? 'border-brand bg-brand/5' : '' }}"
                        href="{{ locale_url($lang->code) }}"
                        data-no-progress data-close-details
                        @if ($lang->code === $locale) aria-current="true" @endif>
                        @if ($lang->flag)
                            <img class="w-6 h-6 rounded-full" loading="lazy" decoding="async" src="{{ asset($lang->flag) }}" width="20" alt="{{ $lang->name }}">
                        @endif
                        <span class="truncate min-w-0">{{ $lang->name }}</span>
                    </a>
                @endforeach
            </div>
    </div>
</details>
