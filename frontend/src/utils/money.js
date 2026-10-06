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
