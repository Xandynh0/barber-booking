import { describe, expect, it } from 'vitest'
import { parseBRLInput, toBRLInput } from './money'

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
