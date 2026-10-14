import { ApiError } from '../../../api/client'
import { formatTime } from '../../../utils/publicFormat'

/** "09:00–09:45" on the barbershop's clock. */
export function timeRange(startIso, endIso, timeZone) {
  return `${formatTime(startIso, timeZone)}–${formatTime(endIso, timeZone)}`
}

/** Accessible name of a reservation card: everything needed to pick it. */
export function appointmentLabel(appointment, timeZone, t) {
  return t('agenda.cardLabel', {
    start: formatTime(appointment.starts_at, timeZone),
    end: formatTime(appointment.ends_at, timeZone),
    customer: appointment.customer_name,
    service: appointment.service.name,
    status: t(`agenda.status.${appointment.status}`),
  })
}

export function blockKindLabel(block, t) {
  return block.kind === 'shop' ? t('agenda.closure') : t('agenda.block')
}

/**
 * The text to show for a failed request. The API's own `error.message` is
 * already translated server-side; an expired session gets one fixed text
 * so the screen can offer the way back to the login.
 */
export function errorText(error, t) {
  if (isSessionError(error)) return t('agenda.sessionExpired')
  if (error instanceof ApiError) return error.message

  return t('common.errors.generic')
}

export function isSessionError(error) {
  return error instanceof ApiError && (error.status === 401 || error.code === 'SESSION_EXPIRED')
}
