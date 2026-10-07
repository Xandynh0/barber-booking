import { describe, expect, it } from 'vitest'
import { parseBRLInput, parseMoneyInput, swapMoneySeparator, toBRLInput, toMoneyInput } from './money'

describe('toBRLInput', () => {
  it('converts an API decimal string to Brazilian display format', () => {
    expect(toBRLInput('45.90')).toBe('45,90')
    expect(toBRLInput('0.00')).toBe('0,00')
    expect(toBRLInput('100')).toBe('100,00')
  })

  it('returns an empty string for null/empty input', () => {
    expect(toBRLInput(null)).toBe('')
    expect(toBRLInput('')).toBe('')
  })
})

describe('parseBRLInput', () => {
  it('parses comma-decimal input into the API decimal format', () => {
    expect(parseBRLInput('45,90')).toBe('45.90')
    expect(parseBRLInput('0,00')).toBe('0.00')
    expect(parseBRLInput('100')).toBe('100.00')
    expect(parseBRLInput('45')).toBe('45.00')
  })

  it('strips thousand separators before the decimal comma', () => {
    expect(parseBRLInput('1.234,56')).toBe('1234.56')
  })

  it('accepts a plain dot-decimal value too', () => {
    expect(parseBRLInput('45.9')).toBe('45.90')
  })

  it('returns null for unparseable input', () => {
    expect(parseBRLInput('')).toBeNull()
    expect(parseBRLInput('abc')).toBeNull()
    expect(parseBRLInput('45,9,0')).toBeNull()
  })
})

describe('toMoneyInput', () => {
  it('formats with a comma for pt-BR', () => {
    expect(toMoneyInput('45.90', 'pt-BR')).toBe('45,90')
    expect(toMoneyInput('0.00', 'pt-BR')).toBe('0,00')
  })

  it('formats with a dot for en', () => {
    expect(toMoneyInput('45.90', 'en')).toBe('45.90')
    expect(toMoneyInput('0.00', 'en')).toBe('0.00')
  })

  it('returns an empty string for null/empty input regardless of language', () => {
    expect(toMoneyInput(null, 'en')).toBe('')
    expect(toMoneyInput('', 'pt-BR')).toBe('')
  })
})

describe('parseMoneyInput', () => {
  it('parses pt-BR comma-decimal input', () => {
    expect(parseMoneyInput('45,90', 'pt-BR')).toBe('45.90')
    expect(parseMoneyInput('0,00', 'pt-BR')).toBe('0.00')
  })

  it('parses en dot-decimal input, treating commas as thousands separators', () => {
    expect(parseMoneyInput('45.90', 'en')).toBe('45.90')
    expect(parseMoneyInput('1,234.56', 'en')).toBe('1234.56')
    expect(parseMoneyInput('0.00', 'en')).toBe('0.00')
  })

  it('returns null for unparseable input in either language', () => {
    expect(parseMoneyInput('abc', 'en')).toBeNull()
    expect(parseMoneyInput('', 'pt-BR')).toBeNull()
  })
})

describe('swapMoneySeparator', () => {
  it('converts a complete pt-BR value to en without changing the amount', () => {
    expect(swapMoneySeparator('45,90', 'pt-BR', 'en')).toBe('45.90')
  })

  it('converts a complete en value to pt-BR without changing the amount', () => {
    expect(swapMoneySeparator('45.90', 'en', 'pt-BR')).toBe('45,90')
  })

  it('converts an incomplete in-progress value without misreading it', () => {
    expect(swapMoneySeparator('45,', 'pt-BR', 'en')).toBe('45.')
    expect(swapMoneySeparator('45,9', 'pt-BR', 'en')).toBe('45.9')
  })

  it('is a no-op for a value with no separator typed yet', () => {
    expect(swapMoneySeparator('45', 'pt-BR', 'en')).toBe('45')
  })

  it('is a no-op when the language does not actually change', () => {
    expect(swapMoneySeparator('45,90', 'pt-BR', 'pt-BR')).toBe('45,90')
  })

  it('is a no-op for an empty value', () => {
    expect(swapMoneySeparator('', 'pt-BR', 'en')).toBe('')
  })
})
