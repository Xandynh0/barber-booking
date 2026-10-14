import { useTranslation } from 'react-i18next'
import { AlertIcon } from '../../../components/PublicIcons'
import { timeRange } from './agendaText'

/** Status in words, never by color alone (planejamento, seção 3). */
export function StatusBadge({ status }) {
  const { t } = useTranslation()

  return <span className={`status-badge status-badge--${status}`}>{t(`agenda.status.${status}`)}</span>
}

/**
 * Inside of a reservation card, shared by the grid and the list. The card
 * itself is a button labelled by appointmentLabel(); this is the visual.
 */
export function AppointmentCardContent({ appointment, timeZone, professionalName }) {
  const { t } = useTranslation()
  const undelivered = appointment.notification_status === 'failed' || appointment.notification_status === 'pending'

  return (
    <>
      <span className="agenda-card-top">
        <span className="agenda-card-time">{timeRange(appointment.starts_at, appointment.ends_at, timeZone)}</span>
        <StatusBadge status={appointment.status} />
      </span>
      <span className="agenda-card-customer">{appointment.customer_name}</span>
      <span className="agenda-card-service">
        {appointment.service.name}
        {professionalName && <> · {professionalName}</>}
      </span>
      {undelivered && (
        <span className="agenda-card-warning">
          <AlertIcon size={14} />
          {t(`agenda.notification.${appointment.notification_status}`)}
        </span>
      )}
    </>
  )
}
