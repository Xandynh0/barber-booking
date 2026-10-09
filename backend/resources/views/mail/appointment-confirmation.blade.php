<x-mail::message>
# {{ __('booking.mail.heading') }}

{{ __('booking.mail.greeting', ['name' => $customerName]) }}

{{ __('booking.mail.intro', ['shop' => $shopName]) }}

<x-mail::table>
| | |
| :--- | :--- |
| **{{ __('booking.fields.service') }}** | {{ $serviceName }} |
| **{{ __('booking.fields.professional') }}** | {{ $professionalName }} |
| **{{ __('booking.fields.date') }}** | {{ $date }} |
| **{{ __('booking.fields.time') }}** | {{ $time }} |
| **{{ __('booking.fields.duration') }}** | {{ __('booking.duration', ['minutes' => $durationMinutes]) }} |
| **{{ __('booking.fields.price') }}** | {{ $price }} |
@if ($shopAddress)
| **{{ __('booking.fields.address') }}** | {{ $shopAddress }} |
@endif
| **{{ __('booking.fields.reference') }}** | {{ $publicId }} |
</x-mail::table>

{{ __('booking.mail.cancel_intro') }}

<x-mail::button :url="$cancellationUrl" color="error">
{{ __('booking.mail.cancel_button') }}
</x-mail::button>

{{ __('booking.mail.cancel_note') }}

@if ($shopPhone)
{{ __('booking.mail.contact', ['phone' => $shopPhone]) }}
@endif

{{ __('booking.mail.signature') }}<br>
{{ $shopName }}
</x-mail::message>
