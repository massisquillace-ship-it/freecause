@extends('layouts.legacy')
@section('title', __('organizations.terms'))
@section('content')
<section class="container py-12 max-w-3xl">
    <h1 class="text-3xl font-bold mb-6">{{ __('organizations.terms') }}</h1>
    <div class="whitespace-pre-line">{{ $terms ?: __('organizations.terms_unavailable') }}</div>
</section>
@endsection
