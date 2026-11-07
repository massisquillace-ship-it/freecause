@extends('emails.layout')
@section('title', $heading.' — FreeCause')
@section('preheader', $heading)
@section('heading', $heading)
@section('content')
    <p style="white-space:pre-line">{{ $bodyText }}</p>
    @if($reason)<p style="white-space:pre-line">{{ $reason }}</p>@endif
@endsection
@section('action', $actionText)
@section('action_url', $destination)
@section('footer')
    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
@endsection
