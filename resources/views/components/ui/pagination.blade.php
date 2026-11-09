@props(['paginator'])

@if (method_exists($paginator, 'hasPages') && $paginator->hasPages())
    @php
        $start = max(1, $paginator->currentPage() - 2);
        $end = min($paginator->lastPage(), $paginator->currentPage() + 2);
        $item = 'fcui-pagination__item';
    @endphp

    <nav aria-label="{{ __('ui.pagination') }}" {{ $attributes->merge(['class' => 'fcui-pagination']) }}>
        @if ($paginator->onFirstPage())
            <span class="{{ $item }} {{ $item }}--disabled" aria-hidden="true">«</span>
        @else
            <a class="{{ $item }}" rel="prev" aria-label="{{ __('ui.previous_page') }}" href="{{ $paginator->previousPageUrl() }}">«</a>
        @endif

        @if ($start > 1)
            <a class="{{ $item }}" href="{{ $paginator->url(1) }}">1</a>
            @if ($start > 2)
                <span class="{{ $item }} {{ $item }}--disabled" aria-hidden="true">…</span>
            @endif
        @endif

        @for ($page = $start; $page <= $end; $page++)
            @if ($page === $paginator->currentPage())
                <span class="{{ $item }} {{ $item }}--current" aria-current="page">{{ $page }}</span>
            @else
                <a class="{{ $item }}" href="{{ $paginator->url($page) }}">{{ $page }}</a>
            @endif
        @endfor

        @if ($end < $paginator->lastPage())
            @if ($end < $paginator->lastPage() - 1)
                <span class="{{ $item }} {{ $item }}--disabled" aria-hidden="true">…</span>
            @endif
            <a class="{{ $item }}" href="{{ $paginator->url($paginator->lastPage()) }}">{{ $paginator->lastPage() }}</a>
        @endif

        @if ($paginator->hasMorePages())
            <a class="{{ $item }}" rel="next" aria-label="{{ __('ui.next_page') }}" href="{{ $paginator->nextPageUrl() }}">»</a>
        @else
            <span class="{{ $item }} {{ $item }}--disabled" aria-hidden="true">»</span>
        @endif
    </nav>
@endif
