{{--
    Line icons of the public identity (same drawings as
    frontend/src/components/PublicIcons.jsx), inline because the page's CSP
    allows no external resource. Decorative: the text beside each icon
    carries the meaning, so they are hidden from assistive technology.
--}}
@php($size = $size ?? 22)
@if ($name === 'pole')
    <svg width="{{ $size / 2 }}" height="{{ $size }}" viewBox="0 0 20 40" aria-hidden="true" focusable="false">
        <defs>
            <pattern id="barber-pole-stripes" width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(35)">
                <rect width="8" height="8" fill="#f3ead9" />
                <rect width="2.6" height="8" fill="#6e1423" />
                <rect x="4" width="2.6" height="8" fill="#1f3a5f" />
            </pattern>
        </defs>
        <rect x="6" y="1" width="8" height="3.5" rx="1.7" fill="#b08d57" />
        <rect x="4" y="4.5" width="12" height="2.5" rx="1" fill="#b08d57" />
        <rect x="5.5" y="7" width="9" height="26" rx="2" fill="url(#barber-pole-stripes)" stroke="#b08d57" stroke-width="0.8" />
        <rect x="4" y="33" width="12" height="2.5" rx="1" fill="#b08d57" />
        <rect x="6" y="35.5" width="8" height="3.5" rx="1.7" fill="#b08d57" />
    </svg>
@else
    <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        @switch($name)
            @case('scissors')
                <circle cx="6" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><path d="M8.1 7.9 20 20" /><path d="M8.1 16.1 20 4" />
                @break
            @case('user')
                <circle cx="12" cy="8" r="4" /><path d="M4.5 20.5c1.2-3.6 4-5.5 7.5-5.5s6.3 1.9 7.5 5.5" />
                @break
            @case('calendar')
                <rect x="3.5" y="5" width="17" height="15.5" rx="2" /><path d="M3.5 10h17M8 3v4M16 3v4" /><path d="M8 14h2M14 14h2M8 17.5h2" />
                @break
            @case('check')
                <path d="m5 12.5 4.5 4.5L19 7.5" />
                @break
            @case('alert')
                <circle cx="12" cy="12" r="8.5" /><path d="M12 7.5v5.5M12 16.2v.1" />
                @break
        @endswitch
    </svg>
@endif
