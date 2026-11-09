@props(['items' => []])

@php
    $items = collect($items)->filter(fn ($item) => ! empty($item['href']) || ! empty($item['label']))->values();
@endphp

@if ($items->isNotEmpty())
    <nav class="fcui-bottom-nav" data-bottom-nav aria-label="{{ __('nav.menu', [], app()->getLocale()) ?: 'FreeCause' }}">
        @foreach ($items as $item)
            @if (! empty($item['href']))
                <a
                    href="{{ $item['href'] }}"
                    @if(!empty($item['ariaLabel'])) aria-label="{{ $item['ariaLabel'] }}" @endif
                    class="fcui-bottom-nav__item {{ ! empty($item['active']) ? 'is-active' : '' }}"
                    @if (! empty($item['active'])) aria-current="page" @endif
                >
                    @if (! empty($item['icon']))
                        <x-dynamic-component :component="'lucide-'.$item['icon']" aria-hidden="true" />
                    @endif
                    <span class="max-w-full whitespace-normal text-center break-words hyphens-auto line-clamp-2">{{ $item['label'] }}</span>
                </a>
            @else
                <button
                    type="button"
                    @if(!empty($item['ariaLabel'])) aria-label="{{ $item['ariaLabel'] }}" @endif
                    class="fcui-bottom-nav__item {{ ! empty($item['active']) ? 'is-active' : '' }}"
                    @if (! empty($item['sheet'])) data-sheet-open="{{ $item['sheet'] }}" @endif
                    @if (! empty($item['active'])) aria-current="page" @endif
                >
                    @if (! empty($item['icon']))
                        <x-dynamic-component :component="'lucide-'.$item['icon']" aria-hidden="true" />
                    @endif
                    <span class="max-w-full whitespace-normal text-center break-words hyphens-auto line-clamp-2">{{ $item['label'] }}</span>
                </button>
            @endif
        @endforeach
    </nav>
@endif
