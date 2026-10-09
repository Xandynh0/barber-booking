@extends('cancellation.layout')

{{-- Invalid/expired link, unknown reservation or too many attempts: never says whether a reservation exists. --}}
@section('content')
    <span class="status-icon">@include('cancellation.icon', ['name' => 'alert', 'size' => 32])</span>
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $message }}</p>
    <div class="actions actions--single">
        <a class="button button--secondary" href="/">{{ __('booking.cancel.home') }}</a>
    </div>
@endsection
