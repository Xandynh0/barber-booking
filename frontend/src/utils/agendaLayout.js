import { formatTime } from './publicFormat'

// Geometry of the admin agenda. Which items belong to the day, their
// clipped (`visible_*`) intervals and the range worth drawing all come from
// the API; this only turns instants into positions.

const MINUTE = 60000

/** Whole minutes from `fromIso` to `toIso`. */
export function minutesBetween(fromIso, toIso) {
  return Math.round((Date.parse(toIso) - Date.parse(fromIso)) / MINUTE)
}

/**
 * Places items of one column: `top`/`height` in minutes from the start of
 * the drawn range, and side-by-side lanes for items that overlap (a
 * cancelled reservation and the new one on the same time, for instance).
 * Items must carry `visible_starts_at`/`visible_ends_at`.
 */
export function layoutColumn(items, rangeStartIso) {
  const sorted = [...items].sort(
    (a, b) => Date.parse(a.visible_starts_at) - Date.parse(b.visible_starts_at) || Date.parse(b.visible_ends_at) - Date.parse(a.visible_ends_at)
  )

  const placed = []
  let cluster = []
  let clusterEnd = -Infinity

  function closeCluster() {
    const lanes = cluster.reduce((max, entry) => Math.max(max, entry.lane + 1), 1)
    cluster.forEach((entry) => {
      entry.lanes = lanes
    })
    cluster = []
  }

  for (const item of sorted) {
    const start = Date.parse(item.visible_starts_at)
    const end = Date.parse(item.visible_ends_at)

    if (start >= clusterEnd) {
      closeCluster()
      clusterEnd = -Infinity
    }

    const busyLanes = new Set(cluster.filter((entry) => entry.end > start).map((entry) => entry.lane))
    let lane = 0
    while (busyLanes.has(lane)) lane++

    const entry = {
      item,
      lane,
      lanes: 1,
      end,
      top: minutesBetween(rangeStartIso, item.visible_starts_at),
      height: Math.max(minutesBetween(item.visible_starts_at, item.visible_ends_at), 1),
    }
    cluster.push(entry)
    placed.push(entry)
    clusterEnd = Math.max(clusterEnd, end)
  }
  closeCluster()

  return placed.map(({ end: _end, ...entry }) => entry)
}

/**
 * Time marks every `stepMinutes` across the drawn range, labelled on the
 * barbershop's clock.
 */
export function timeMarks(hours, timeZone, stepMinutes = 30) {
  if (!hours) return []
  const total = minutesBetween(hours.starts_at, hours.ends_at)
  const marks = []

  for (let offset = 0; offset < total; offset += stepMinutes) {
    const iso = new Date(Date.parse(hours.starts_at) + offset * MINUTE).toISOString()
    marks.push({ offset, label: formatTime(iso, timeZone), isHour: offset % 60 === 0 })
  }

  return marks
}

/** Minutes from the range start to `nowIso`, or null when outside it. */
export function nowOffset(hours, nowIso) {
  if (!hours) return null
  const offset = minutesBetween(hours.starts_at, nowIso)

  return offset >= 0 && offset <= minutesBetween(hours.starts_at, hours.ends_at) ? offset : null
}

/**
 * The mobile list: reservations and blocks of the day in chronological
 * order (blocks of a shop closure appear once per professional, which is
 * what each professional's day looks like).
 */
export function chronological(appointments, blocks) {
  return [
    ...appointments.map((appointment) => ({ type: 'appointment', key: `a-${appointment.public_id}`, value: appointment })),
    ...blocks.map((block) => ({ type: 'block', key: `b-${block.id}`, value: block })),
  ].sort(
    (a, b) =>
      Date.parse(a.value.visible_starts_at) - Date.parse(b.value.visible_starts_at) ||
      (a.type === b.type ? 0 : a.type === 'block' ? -1 : 1)
  )
}
