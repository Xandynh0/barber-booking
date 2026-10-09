import { addDays, utcIsoToZonedParts } from './timezone'

// Display helpers for the public pages. Every date and time is shown in the
// barbershop's timezone (never the browser's), in the current language.

function intlLocale(language) {
  return language === 'en' ? 'en-US' : 'pt-BR'
}

/** "55.90" → "R$ 55,90" (pt-BR) / "R$55.90" (en). */
export function formatPrice(decimalString, language) {
  return new Intl.NumberFormat(intlLocale(language), { style: 'currency', currency: 'BRL' }).format(
    Number(decimalString)
  )
}

/** Today's calendar date ("YYYY-MM-DD") in the barbershop's timezone. */
export function todayInZone(timeZone, now = new Date()) {
  return utcIsoToZonedParts(now.toISOString(), timeZone).date
}

/**
 * The bookable dates: today plus `horizonDays - 1` days, counted on the
 * barbershop's calendar — the same rule the API applies. This only decides
 * which dates to offer; whether a date has slots is always the API's answer.
 */
export function horizonDates(timeZone, horizonDays, now = new Date()) {
  const today = todayInZone(timeZone, now)

  return Array.from({ length: Math.max(0, horizonDays) }, (_, offset) => addDays(today, offset))
}

/**
 * A calendar date as a label. The date has no time, so it is formatted at
 * UTC noon to stay on the same day regardless of any timezone.
 */
export function formatDateLabel(dateStr, language, options = { weekday: 'short', day: '2-digit', month: '2-digit' }) {
  const [year, month, day] = dateStr.split('-').map(Number)

  return new Intl.DateTimeFormat(intlLocale(language), { ...options, timeZone: 'UTC' }).format(
    new Date(Date.UTC(year, month - 1, day, 12))
  )
}

export function formatLongDate(dateStr, language) {
  return formatDateLabel(dateStr, language, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

/** A UTC instant as "HH:MM" on the barbershop's clock. */
export function formatTime(iso, timeZone) {
  return utcIsoToZonedParts(iso, timeZone).time
}

/** A UTC instant's calendar date on the barbershop's clock. */
export function zonedDate(iso, timeZone) {
  return utcIsoToZonedParts(iso, timeZone).date
}
