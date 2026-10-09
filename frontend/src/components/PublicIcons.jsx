// Line icons for the public pages, drawn after the approved reference
// (docs/design/old-barber.png). Inline SVG, no icon dependency. They are
// decorative: the text next to each one always carries the meaning, so they
// are hidden from assistive technology.

function Icon({ children, size = 20, className = '' }) {
  return (
    <svg
      className={`public-icon ${className}`.trim()}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {children}
    </svg>
  )
}

export function ScissorsIcon(props) {
  return (
    <Icon {...props}>
      <circle cx="6" cy="6" r="3" />
      <circle cx="6" cy="18" r="3" />
      <path d="M8.1 7.9 20 20" />
      <path d="M8.1 16.1 20 4" />
      <path d="m14.5 12 .01.01" />
    </Icon>
  )
}

export function UserIcon(props) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4.5 20.5c1.2-3.6 4-5.5 7.5-5.5s6.3 1.9 7.5 5.5" />
    </Icon>
  )
}

export function CalendarIcon(props) {
  return (
    <Icon {...props}>
      <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
      <path d="M3.5 10h17M8 3v4M16 3v4" />
      <path d="M8 14h2M14 14h2M8 17.5h2" />
    </Icon>
  )
}

export function ClockIcon(props) {
  return (
    <Icon {...props}>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </Icon>
  )
}

export function CheckIcon(props) {
  return (
    <Icon {...props}>
      <path d="m5 12.5 4.5 4.5L19 7.5" />
    </Icon>
  )
}

export function ArrowRightIcon(props) {
  return (
    <Icon {...props}>
      <path d="M5 12h14M13 6l6 6-6 6" />
    </Icon>
  )
}

export function ChevronLeftIcon(props) {
  return (
    <Icon {...props}>
      <path d="m14.5 6-6 6 6 6" />
    </Icon>
  )
}

export function ChevronRightIcon(props) {
  return (
    <Icon {...props}>
      <path d="m9.5 6 6 6-6 6" />
    </Icon>
  )
}

export function MapPinIcon(props) {
  return (
    <Icon {...props}>
      <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11Z" />
      <circle cx="12" cy="10" r="2.3" />
    </Icon>
  )
}

export function PhoneIcon(props) {
  return (
    <Icon {...props}>
      <path d="M5 4h3.5l1.7 4.3-2.2 1.4a11 11 0 0 0 6.3 6.3l1.4-2.2L20 15.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4Z" />
    </Icon>
  )
}

export function MailIcon(props) {
  return (
    <Icon {...props}>
      <rect x="3.5" y="5.5" width="17" height="13" rx="2" />
      <path d="m4 7 8 6 8-6" />
    </Icon>
  )
}

/** The barber pole of the reference's brand mark, in the brand colors. */
export function BarberPoleIcon({ size = 40, className = '' }) {
  const id = 'barber-pole-stripes'
  return (
    <svg
      className={`public-icon ${className}`.trim()}
      width={size * 0.5}
      height={size}
      viewBox="0 0 20 40"
      aria-hidden="true"
      focusable="false"
    >
      <defs>
        <pattern id={id} width="8" height="8" patternUnits="userSpaceOnUse" patternTransform="rotate(35)">
          <rect width="8" height="8" fill="#f3ead9" />
          <rect width="2.6" height="8" fill="#6e1423" />
          <rect x="4" width="2.6" height="8" fill="#1f3a5f" />
        </pattern>
      </defs>
      <rect x="6" y="1" width="8" height="3.5" rx="1.7" fill="#b08d57" />
      <rect x="4" y="4.5" width="12" height="2.5" rx="1" fill="#b08d57" />
      <rect x="5.5" y="7" width="9" height="26" rx="2" fill={`url(#${id})`} stroke="#b08d57" strokeWidth="0.8" />
      <rect x="4" y="33" width="12" height="2.5" rx="1" fill="#b08d57" />
      <rect x="6" y="35.5" width="8" height="3.5" rx="1.7" fill="#b08d57" />
    </svg>
  )
}
