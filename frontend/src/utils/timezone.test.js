import { describe, expect, it } from 'vitest'
import { addDays, utcIsoToZonedParts, zonedWallTimeToUtcIso } from './timezone'

describe('zonedWallTimeToUtcIso', () => {
  it('converts a São Paulo wall-clock time to its UTC instant', () => {
    const iso = zonedWallTimeToUtcIso('2026-12-24', '18:00', 'America/Sao_Paulo')

    expect(iso).toBe(new Date('2026-12-24T21:00:00.000Z').toISOString())
  })

  it('handles a UTC timezone as a no-op', () => {
    const iso = zonedWallTimeToUtcIso('2026-06-01', '09:00', 'UTC')

    expect(iso).toBe(new Date('2026-06-01T09:00:00.000Z').toISOString())
  })
})

describe('utcIsoToZonedParts', () => {
  it('converts a UTC instant back to São Paulo wall-clock date/time', () => {
    const parts = utcIsoToZonedParts('2026-12-24T21:00:00.000Z', 'America/Sao_Paulo')

    expect(parts).toEqual({ date: '2026-12-24', time: '18:00' })
  })

  it('round-trips through both conversions', () => {
    const iso = zonedWallTimeToUtcIso('2026-03-10', '07:30', 'America/Sao_Paulo')
    const parts = utcIsoToZonedParts(iso, 'America/Sao_Paulo')

    expect(parts).toEqual({ date: '2026-03-10', time: '07:30' })
  })
})

describe('addDays', () => {
  it('adds a day within the same month', () => {
    expect(addDays('2026-12-24', 1)).toBe('2026-12-25')
  })

  it('rolls over to the next month', () => {
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01')
  })
})
