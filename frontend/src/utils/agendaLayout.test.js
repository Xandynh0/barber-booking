import { describe, expect, it } from 'vitest'
import { chronological, layoutColumn, minutesBetween, nowOffset, timeMarks } from './agendaLayout'

const item = (id, start, end) => ({
  id,
  visible_starts_at: `2026-11-02T${start}:00+00:00`,
  visible_ends_at: `2026-11-02T${end}:00+00:00`,
})

describe('agenda layout', () => {
  it('places items by minutes from the range start', () => {
    const [entry] = layoutColumn([item('a', '13:00', '13:45')], '2026-11-02T12:00:00+00:00')

    expect(entry).toMatchObject({ top: 60, height: 45, lane: 0, lanes: 1 })
  })

  it('puts overlapping items side by side and lets adjacent ones share a lane', () => {
    const placed = layoutColumn(
      [item('a', '13:00', '13:45'), item('b', '13:15', '14:00'), item('c', '14:00', '14:30')],
      '2026-11-02T12:00:00+00:00'
    )
    const byId = Object.fromEntries(placed.map((entry) => [entry.item.id, entry]))

    expect(byId.a).toMatchObject({ lane: 0, lanes: 2 })
    expect(byId.b).toMatchObject({ lane: 1, lanes: 2 })
    // Starts exactly when "b" ends: a new cluster, full width.
    expect(byId.c).toMatchObject({ lane: 0, lanes: 1 })
  })

  it('labels time marks on the barbershop clock', () => {
    const marks = timeMarks({ starts_at: '2026-11-02T12:00:00+00:00', ends_at: '2026-11-02T13:30:00+00:00' }, 'America/Sao_Paulo')

    expect(marks.map((mark) => mark.label)).toEqual(['09:00', '09:30', '10:00'])
    expect(marks.map((mark) => mark.isHour)).toEqual([true, false, true])
  })

  it('places the now line only inside the drawn range', () => {
    const hours = { starts_at: '2026-11-02T12:00:00+00:00', ends_at: '2026-11-02T21:00:00+00:00' }

    expect(nowOffset(hours, '2026-11-02T13:30:00+00:00')).toBe(90)
    expect(nowOffset(hours, '2026-11-02T22:00:00+00:00')).toBeNull()
    expect(nowOffset(null, '2026-11-02T13:30:00+00:00')).toBeNull()
  })

  it('orders the mobile list by start, a block before a reservation at the same time', () => {
    const list = chronological(
      [{ public_id: 'x', ...item('x', '14:00', '14:45') }, { public_id: 'y', ...item('y', '13:00', '13:45') }],
      [{ id: 1, ...item(1, '14:00', '15:00') }]
    )

    expect(list.map((entry) => entry.key)).toEqual(['a-y', 'b-1', 'a-x'])
  })

  it('counts whole minutes', () => {
    expect(minutesBetween('2026-11-02T12:00:00+00:00', '2026-11-02T12:45:00+00:00')).toBe(45)
  })
})
