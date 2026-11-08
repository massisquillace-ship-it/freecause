@extends('admin.layouts.app')
@section('title', __('organizations.create_unclaimed'))
@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('organizations.create_unclaimed') }}</h1>
@if($errors->any())<div role="alert" class="mb-6">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="post" action="{{ route('admin.organizations.unclaimed.store') }}" class="space-y-5 max-w-3xl" data-turbo="false">
    @csrf
    <label class="block">{{ __('organizations.name') }}<input name="name" value="{{ old('name') }}" maxlength="120" class="block w-full border border-border p-3" required></label>
    <label class="block">{{ __('organizations.country') }}<select name="country" class="block w-full border border-border p-3" required>@foreach($countries as $code=>$name)<option value="{{ $code }}" @selected(old('country') === $code)>{{ $name }}</option>@endforeach</select></label>
    <fieldset><legend class="font-semibold mb-3">{{ __('organizations.causes') }}</legend><div class="grid sm:grid-cols-2 gap-3">@foreach($causes as $cause)<label class="flex gap-2"><input type="checkbox" name="causes[]" value="{{ $cause->slug }}" @checked(in_array($cause->slug, old('causes', [])))> {{ $cause->label() }}</label>@endforeach</div></fieldset>
    <label class="block">{{ __('organizations.petition_ids') }}<textarea name="petition_ids" rows="4" maxlength="3000" class="block w-full border border-border p-3">{{ old('petition_ids') }}</textarea></label>
    <p>{{ __('organizations.import_help') }}</p>
    <x-blatui.button type="submit">{{ __('organizations.create_unclaimed') }}</x-blatui.button>
</form>
@endsection
