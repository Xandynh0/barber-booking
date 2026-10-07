/**
 * Converts the API's decimal string ("45.9") into the Brazilian input
 * format ("45,90") for display in an editable field.
 */
export function toBRLInput(decimalString) {
  if (decimalString == null || decimalString === '') return ''

  const [integerPart, decimalPart = ''] = String(decimalString).split('.')

  return `${integerPart},${decimalPart.padEnd(2, '0').slice(0, 2)}`
}

/**
 * Parses a Brazilian-formatted money input ("45,90", "1.234,56", or a
 * plain "45.90") into the API's canonical decimal string ("45.90").
 * Returns null if the value isn't a recognizable number.
 */
export function parseBRLInput(value) {
  if (typeof value !== 'string') return null

  const trimmed = value.trim()
  if (trimmed === '') return null

  const normalized = trimmed.includes(',')
    ? trimmed.replace(/\./g, '').replace(',', '.')
    : trimmed

  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) {
    return null
  }

  const [integerPart, decimalPart = ''] = normalized.split('.')

  return `${integerPart}.${decimalPart.padEnd(2, '0').slice(0, 2)}`
}

/**
 * Converts the API's decimal string ("45.9") into the input format for a
 * given UI language — comma-decimal for pt-BR ("45,90"), dot-decimal for
 * en ("45.90"). The currency itself never changes (always BRL); only the
 * separator convention does.
 */
export function toMoneyInput(decimalString, language) {
  return language === 'en' ? toEnUSInput(decimalString) : toBRLInput(decimalString)
}

function toEnUSInput(decimalString) {
  if (decimalString == null || decimalString === '') return ''

  const [integerPart, decimalPart = ''] = String(decimalString).split('.')

  return `${integerPart}.${decimalPart.padEnd(2, '0').slice(0, 2)}`
}

/**
 * Parses a money input already formatted for `language` into the API's
 * canonical decimal string ("45.90"). Returns null if unparseable.
 */
export function parseMoneyInput(value, language) {
  return language === 'en' ? parseEnUSInput(value) : parseBRLInput(value)
}

function parseEnUSInput(value) {
  if (typeof value !== 'string') return null

  const trimmed = value.trim()
  if (trimmed === '') return null

  // en-US: ',' is a thousands separator, '.' is the decimal point.
  const normalized = trimmed.replace(/,/g, '')

  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) {
    return null
  }

  const [integerPart, decimalPart = ''] = normalized.split('.')

  return `${integerPart}.${decimalPart.padEnd(2, '0').slice(0, 2)}`
}

/**
 * Converts an already-typed money input from one language's separator
 * convention to another's, preserving the digits (and incomplete entries)
 * exactly — a simple decimal-separator swap, not a re-parse. Used when the
 * UI language changes mid-edit so an in-progress value like "45," or a
 * finished one like "45,90" is never silently reinterpreted as a different
 * number (e.g. treating the comma as a thousands separator and discarding
 * it). Does not handle values that already use a thousands separator in
 * the *previous* language (e.g. "1.234,56" swapping to en) — unrealistic
 * for this app's price range (services, not bulk amounts).
 */
export function swapMoneySeparator(value, fromLanguage, toLanguage) {
  if (typeof value !== 'string' || value === '' || fromLanguage === toLanguage) {
    return value
  }

  return toLanguage === 'en' ? value.replace(/,/g, '.') : value.replace(/\./g, ',')
}
