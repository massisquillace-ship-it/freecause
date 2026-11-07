@if($petition->status === 'published' && $petition->is_active && $petition->outcome !== 'open')
<section class="border border-border rounded-lg p-4 my-4" aria-label="{{ __('organizations.outcome_title') }}">
    <p class="font-bold">{{ __('organizations.outcome_'.$petition->outcome) }}</p>
    @if($petition->outcome_at)<time datetime="{{ $petition->outcome_at->toDateString() }}">{{ $petition->outcome_at->toDateString() }}</time>@endif
    @if($petition->outcome_summary)<p class="mt-2 whitespace-pre-line break-words">{{ $petition->outcome_summary }}</p>@endif
</section>
@endif
