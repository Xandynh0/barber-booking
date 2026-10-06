/**
 * Minutes to add to a UTC timestamp to get the wall-clock time in
 * `timeZone` at that instant (e.g. -180 for America/Sao_Paulo, which has no
 * DST since 2019 but this still asks the platform rather than hardcoding an
 * offset, since the configured timezone is not guaranteed to be that one).
 */
function offsetMinutesAt(utcMs, timeZone) {
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })

  const parts = formatter.formatToParts(new Date(utcMs)).reduce((acc, part) => {
    acc[part.type] = part.value
    return acc
  }, {})

  const asIfUtc = Date.UTC(
    Number(parts.year),
    Number(parts.month) - 1,
    Number(parts.day),
    parts.hour === '24' ? 0 : Number(parts.hour),
    Number(parts.minute),
    Number(parts.second)
  )

  return (asIfUtc - utcMs) / 60000
}

/**
 * Converts a wall-clock date/time meant as local time in `timeZone` (never
 * the browser's own timezone) into a UTC ISO 8601 string with an explicit
 * offset, matching the API's input convention
 * (docs/planejamento-barbearia-mvp.md section 11).
 */
export function zonedWallTimeToUtcIso(dateStr, timeStr, timeZone) {
  const [year, month, day] = dateStr.split('-').map(Number)
  const [hour, minute] = timeStr.split(':').map(Number)
  const naiveUtcMs = Date.UTC(year, month - 1, day, hour, minute, 0)

  // The offset can itself depend on the instant (DST transitions), so this
  // resolves it using a first guess and then re-checks once at the
  // corrected instant — enough for any real-world timezone transition.
  const firstGuessOffset = offsetMinutesAt(naiveUtcMs, timeZone)
  const correctedOffset = offsetMinutesAt(naiveUtcMs - firstGuessOffset * 60000, timeZone)

  return new Date(naiveUtcMs - correctedOffset * 60000).toISOString()
}

/**
 * Converts a UTC ISO 8601 instant into the date/time wall-clock parts as
 * seen in `timeZone`, for display — never the browser's own timezone.
 */
export function utcIsoToZonedParts(iso, timeZone) {
  const date = new Date(iso)
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })

  const parts = formatter.formatToParts(date).reduce((acc, part) => {
    acc[part.type] = part.value
    return acc
  }, {})

  return {
    date: `${parts.year}-${parts.month}-${parts.day}`,
    time: `${parts.hour}:${parts.minute}`,
  }
}
