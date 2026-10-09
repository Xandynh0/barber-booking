@extends('cancellation.layout')

{{--
    Minimal summary only (seção 6): no customer name, e-mail or phone. Opening
    this page never changes anything — only the POST below cancels, so an
    e-mail scanner following the link cannot cancel a reservation.
--}}
@section('content')
    <h1>{{ $title }}</h1>

    @if ($state === 'cancelled')
        <p class="notice notice--success" role="status">{{ __('booking.cancel.done') }}</p>
    @elseif ($state === 'already_cancelled')
        <p class="notice" role="status">{{ __('booking.cancel.already') }}</p>
    @elseif ($state === 'deadline_passed')
        <p class="notice" role="status">{{ __('booking.cancel.deadline_passed') }}</p>
    @endif

    <dl>
        <dt>{{ __('booking.fields.service') }}</dt><dd>{{ $serviceName }}</dd>
        <dt>{{ __('booking.fields.professional') }}</dt><dd>{{ $professionalName }}</dd>
        <dt>{{ __('booking.fields.date') }}</dt><dd>{{ $date }}</dd>
        <dt>{{ __('booking.fields.time') }}</dt><dd>{{ $time }}</dd>
        <dt>{{ __('booking.fields.reference') }}</dt><dd>{{ $publicId }}</dd>
    </dl>

    @if ($state === 'confirm')
        <p>{{ __('booking.cancel.question') }}</p>
        <form method="POST" action="{{ $formAction }}">
            @csrf
            <button type="submit">{{ __('booking.cancel.button') }}</button>
        </form>
    @endif
@endsection
