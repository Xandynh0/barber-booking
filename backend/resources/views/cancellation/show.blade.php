@extends('cancellation.layout')

{{--
    Minimal summary only (seção 6): no customer name, e-mail or phone. Opening
    this page never changes anything — only the POST below cancels, so an
    e-mail scanner following the link cannot cancel a reservation. Each state
    is rendered alone (docs/design/3.png shows them side by side only as a
    board); the links below are plain navigation, they change nothing.
--}}
@section('content')
    @if ($state === 'cancelled')
        <span class="status-icon status-icon--success">@include('cancellation.icon', ['name' => 'check', 'size' => 34])</span>
        <h1>{{ __('booking.cancel.done_title') }}</h1>
        <p class="lead" role="status">{{ __('booking.cancel.done') }}</p>
        <div class="actions actions--single">
            <a class="button button--primary" href="/agendar">{{ __('booking.cancel.book_again') }}</a>
        </div>
    @else
        @if ($state === 'confirm')
            <span class="status-icon status-icon--danger">@include('cancellation.icon', ['name' => 'calendar', 'size' => 32])</span>
            <h1>{{ __('booking.cancel.heading') }}</h1>
            <p class="lead">{{ __('booking.cancel.question') }}</p>
        @elseif ($state === 'already_cancelled')
            <span class="status-icon">@include('cancellation.icon', ['name' => 'alert', 'size' => 32])</span>
            <h1>{{ __('booking.cancel.already_title') }}</h1>
            <p class="lead" role="status">{{ __('booking.cancel.already') }}</p>
        @else
            <span class="status-icon">@include('cancellation.icon', ['name' => 'alert', 'size' => 32])</span>
            <h1>{{ __('booking.cancel.deadline_title') }}</h1>
            <p class="lead" role="status">{{ __('booking.cancel.deadline_passed') }}</p>
        @endif

        <div class="summary">
            <div class="summary-row">
                <span class="summary-icon">@include('cancellation.icon', ['name' => 'scissors'])</span>
                <dl>
                    <dt class="sr-only">{{ __('booking.fields.service') }}</dt>
                    <dd class="summary-main">{{ $serviceName }}</dd>
                    <dd class="summary-sub">{{ $duration }} · {{ $price }}</dd>
                </dl>
            </div>
            <div class="summary-row">
                <span class="summary-icon">@include('cancellation.icon', ['name' => 'user'])</span>
                <dl>
                    <dt class="sr-only">{{ __('booking.fields.professional') }}</dt>
                    <dd class="summary-main">{{ $professionalName }}</dd>
                    <dd class="summary-sub">{{ __('booking.fields.professional') }}</dd>
                </dl>
            </div>
            <div class="summary-row">
                <span class="summary-icon">@include('cancellation.icon', ['name' => 'calendar'])</span>
                <dl>
                    <dt class="sr-only">{{ __('booking.fields.date') }}</dt>
                    <dd class="summary-main">{{ $date }} · {{ $time }}</dd>
                    <dd class="summary-sub">{{ $weekday }}</dd>
                </dl>
            </div>
        </div>

        <p class="reference">{{ __('booking.fields.reference') }}: <code>{{ $publicId }}</code></p>

        @if ($state === 'confirm')
            <div class="actions">
                <a class="button button--secondary" href="/">{{ __('booking.cancel.keep') }}</a>
                <form method="POST" action="{{ $formAction }}">
                    @csrf
                    <button type="submit" class="button button--danger">{{ __('booking.cancel.button') }}</button>
                </form>
            </div>
        @elseif ($state === 'already_cancelled')
            <div class="actions actions--single">
                <a class="button button--primary" href="/agendar">{{ __('booking.cancel.book_again') }}</a>
            </div>
        @else
            <div class="actions actions--single">
                <a class="button button--secondary" href="/">{{ __('booking.cancel.home') }}</a>
            </div>
        @endif
    @endif
@endsection
