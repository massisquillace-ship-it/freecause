@props(['items' => []])

<nav aria-label="{{ __('ui.breadcrumb') }}" {{ $attributes->merge(['class' => 'fcui-breadcrumb']) }}>
    <ol>
        @foreach ($items as $item)
            @php
                $label = is_array($item) ? $item['label'] ?? '' : $item;
                $url = is_array($item) ? $item['url'] ?? null : null;
            @endphp

            <li>
                @if ($url && ! $loop->last)
                    <a href="{{ $url }}">{{ $label }}</a>
                @else
                    <span aria-current="page">{{ $label }}</span>
                @endif
            </li>

            @unless ($loop->last)
                <li class="fcui-breadcrumb__sep" aria-hidden="true">/</li>
            @endunless
        @endforeach
    </ol>
</nav>
