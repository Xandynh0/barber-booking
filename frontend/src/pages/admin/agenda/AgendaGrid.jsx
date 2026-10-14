import { useTranslation } from 'react-i18next'
import { BanIcon, CoffeeIcon } from '../../../components/PublicIcons'
import { layoutColumn, minutesBetween, nowOffset, timeMarks } from '../../../utils/agendaLayout'
import { zonedDate } from '../../../utils/publicFormat'
import { AppointmentCardContent } from './AppointmentCardContent'
import { appointmentLabel, blockKindLabel, timeRange } from './agendaText'

// By duration in minutes, at the grid's 2.2px per minute: under 20 minutes
// only one line fits, under 30 two (time and customer), from 30 all three.
function sizeOf(minutes) {
  if (minutes < 20) return 'xs'
  if (minutes < 30) return 's'
  return 'm'
}

function positionStyle(entry) {
  return {
    '--top': entry.top,
    '--height': entry.height,
    '--lane': entry.lane,
    '--lanes': entry.lanes,
  }
}

/**
 * Desktop day grid after docs/design/1.png: time down the side, one column
 * per professional. Positions come from the API's clipped intervals; the
 * grid scrolls sideways only when there are more professionals than fit.
 */
export function AgendaGrid({ agenda, appointments, onOpen }) {
  const { t } = useTranslation()
  const { hours, timezone: timeZone } = agenda
  const total = minutesBetween(hours.starts_at, hours.ends_at)
  const marks = timeMarks(hours, timeZone)
  const isToday = zonedDate(agenda.now, timeZone) === agenda.date
  const now = isToday ? nowOffset(hours, agenda.now) : null
  const columns = `4.5rem repeat(${agenda.professionals.length}, minmax(12.5rem, 1fr))`

  return (
    <div className="agenda-grid-scroll">
      <div className="agenda-grid" style={{ '--total': total }}>
        <div className="agenda-grid-head" style={{ gridTemplateColumns: columns }}>
          <div className="agenda-grid-corner">{t('agenda.timeColumn')}</div>
          {agenda.professionals.map((professional) => (
            <div key={professional.id} className="agenda-grid-professional">
              <strong>{professional.name}</strong>
              <span>
                {t('agenda.professionalRole')}
                {!professional.is_active && <> · {t('agenda.inactive')}</>}
              </span>
            </div>
          ))}
        </div>

        <div className="agenda-grid-body" style={{ gridTemplateColumns: columns }}>
          <div className="agenda-grid-times" aria-hidden="true">
            {marks.map((mark) => (
              <span
                key={mark.offset}
                className={`agenda-grid-mark${mark.isHour ? ' agenda-grid-mark--hour' : ''}`}
                style={{ '--top': mark.offset }}
              >
                {mark.label}
              </span>
            ))}
          </div>

          {agenda.professionals.map((professional) => {
            const blocks = agenda.blocks.filter((block) => block.professional_id === professional.id)
            const own = appointments.filter((appointment) => appointment.professional_id === professional.id)
            const placed = layoutColumn([...blocks.map((block) => ({ ...block, _type: 'block' })), ...own], hours.starts_at)

            return (
              <ul key={professional.id} className="agenda-column" aria-label={t('agenda.columnLabel', { name: professional.name })}>
                {professional.working_periods.map((period) => (
                  <li
                    key={`w-${period.starts_at}`}
                    className="agenda-working"
                    aria-hidden="true"
                    style={{
                      '--top': minutesBetween(hours.starts_at, period.starts_at),
                      '--height': minutesBetween(period.starts_at, period.ends_at),
                    }}
                  />
                ))}

                {professional.breaks.map((gap) => (
                  <li
                    key={`p-${gap.starts_at}`}
                    className="agenda-break"
                    style={{
                      '--top': minutesBetween(hours.starts_at, gap.starts_at),
                      '--height': minutesBetween(gap.starts_at, gap.ends_at),
                    }}
                  >
                    <CoffeeIcon size={18} />
                    <span>
                      <strong>{t('agenda.break')}</strong>
                      <span>{timeRange(gap.starts_at, gap.ends_at, timeZone)}</span>
                    </span>
                  </li>
                ))}

                {placed.map((entry) =>
                  entry.item._type === 'block' ? (
                    <li
                      key={`b-${entry.item.id}`}
                      className={`agenda-block agenda-block--${entry.item.kind}`}
                      style={positionStyle(entry)}
                      data-size={sizeOf(entry.height)}
                    >
                      <BanIcon size={16} />
                      <span>
                        <strong>{blockKindLabel(entry.item, t)}</strong>
                        <span>{timeRange(entry.item.starts_at, entry.item.ends_at, timeZone)}</span>
                        {entry.item.reason && <span className="agenda-block-reason">{entry.item.reason}</span>}
                      </span>
                    </li>
                  ) : (
                    <li key={entry.item.public_id} className="agenda-slot" style={positionStyle(entry)}>
                      <button
                        type="button"
                        className={`agenda-card agenda-card--${entry.item.status}`}
                        data-size={sizeOf(entry.height)}
                        aria-label={appointmentLabel(entry.item, timeZone, t)}
                        title={appointmentLabel(entry.item, timeZone, t)}
                        onClick={() => onOpen(entry.item.public_id)}
                      >
                        <AppointmentCardContent appointment={entry.item} timeZone={timeZone} />
                      </button>
                    </li>
                  )
                )}
              </ul>
            )
          })}

          {now !== null && (
            <div className="agenda-now" style={{ '--top': now }} aria-hidden="true">
              <span>{t('agenda.now')}</span>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
