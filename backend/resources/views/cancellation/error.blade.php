@extends('cancellation.layout')

{{-- Invalid/expired link, unknown reservation or too many attempts: never says whether a reservation exists. --}}
@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
@endsection
