@extends(request()->routeIs('admin.*') ? 'admin.layouts.app' : 'layouts.legacy')
@section('title', __('organizations.update_outcome'))
@section('content')
<section class="container py-10 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.update_outcome') }}</h1>
    <p class="mb-4">{{ __('organizations.outcome_help') }}</p>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <form method="post" class="space-y-4">@csrf
        <label class="block" for="outcome">{{ __('organizations.outcome_title') }}</label>
        <select id="outcome" name="outcome" class="block w-full border border-border p-3">@foreach(\App\Models\Petition::OUTCOMES as $outcome)<option value="{{ $outcome }}" @selected(old('outcome',$petition->outcome)===$outcome)>{{ __('organizations.outcome_'.$outcome) }}</option>@endforeach</select>
        <label class="block" for="outcome-date">{{ __('organizations.outcome_date') }}</label><input class="block w-full border border-border p-3" id="outcome-date" name="outcome_at" type="date" max="{{ now()->toDateString() }}" value="{{ old('outcome_at',$petition->outcome_at?->toDateString()) }}">
        <label class="block" for="outcome-summary">{{ __('organizations.outcome_summary') }}</label><textarea class="block w-full border border-border p-3" rows="5" id="outcome-summary" name="outcome_summary" maxlength="500">{{ old('outcome_summary',$petition->outcome_summary) }}</textarea>
        @if($petition->outcome !== 'open')<label class="flex gap-2"><input name="confirm_reopen" type="checkbox" value="1">{{ __('organizations.outcome_reopen_confirm') }}</label>@endif
        <x-ui.button>{{ __('organizations.save_outcome') }}</x-ui.button>
    </form>
</section>
@endsection
