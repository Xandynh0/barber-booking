import { useTranslation } from 'react-i18next'
import { BanIcon } from '../../../components/PublicIcons'
import { chronological } from '../../../utils/agendaLayout'
import { AppointmentCardContent } from './AppointmentCardContent'
import { appointmentLabel, blockKindLabel, timeRange } from './agendaText'

/**
 * Phone layout after docs/design/1.png: the day's reservations and blocks
 * in chronological order, one card per line — no sideways scrolling.
 */
export function AgendaList({ agenda, appointments, onOpen }) {
  const { t } = useTranslation()
  const timeZone = agenda.timezone
  const names = Object.fromEntries(agenda.professionals.map((professional) => [professional.id, professional.name]))
  const entries = chronological(appointments, agenda.blocks)

  return (
    <ol className="agenda-list" aria-label={t('agenda.title')}>
      {entries.map((entry) =>
        entry.type === 'appointment' ? (
          <li key={entry.key}>
            <button
              type="button"
              className={`agenda-card agenda-card--${entry.value.status} agenda-card--row`}
              aria-label={`${appointmentLabel(entry.value, timeZone, t)} — ${names[entry.value.professional_id] ?? ''}`}
              onClick={() => onOpen(entry.value.public_id)}
            >
              <AppointmentCardContent appointment={entry.value} timeZone={timeZone} professionalName={names[entry.value.professional_id]} />
            </button>
          </li>
        ) : (
          <li key={entry.key} className={`agenda-list-block agenda-block--${entry.value.kind}`}>
            <BanIcon size={18} />
            <span>
              <strong>
                {blockKindLabel(entry.value, t)} · {timeRange(entry.value.starts_at, entry.value.ends_at, timeZone)}
              </strong>
              <span>
                {names[entry.value.professional_id]}
                {entry.value.reason && <> · {entry.value.reason}</>}
                {entry.value.continues_before && <> · {t('agenda.continuesBefore')}</>}
                {entry.value.continues_after && <> · {t('agenda.continuesAfter')}</>}
              </span>
            </span>
          </li>
        )
      )}
    </ol>
  )
}
