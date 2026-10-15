import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { getAppointment, runAppointmentAction } from '../../../api/agenda'
import { Modal } from '../../../components/Modal'
import { BanIcon } from '../../../components/PublicIcons'
import { formatLongDate, formatPrice, formatTime, zonedDate } from '../../../utils/publicFormat'
import { StatusBadge } from './AppointmentCardContent'
import { errorText, isSessionError, timeRange } from './agendaText'

const STATUS_ACTIONS = [
  { action: 'complete', labelKey: 'agenda.details.complete', className: 'primary-button' },
  { action: 'no_show', labelKey: 'agenda.details.noShow', className: 'secondary-button' },
]

/**
 * Details of one reservation with the actions the API allows right now
 * (`allowed_actions`). Cancelling asks for confirmation first; resending
 * reports the outcome of that attempt apart from the reservation, which it
 * never changes. After any change `onChanged` refreshes the agenda.
 */
export function AppointmentDetails({ publicId, onClose, onChanged, onSessionExpired }) {
  const { t, i18n } = useTranslation()
  const [answer, setAnswer] = useState(null) // { data } | { error }
  const [busy, setBusy] = useState(null) // action in flight
  const [confirmingCancel, setConfirmingCancel] = useState(false)
  const [feedback, setFeedback] = useState(null) // { kind: 'success' | 'error', text }
  const keepRef = useRef(null)
  const cancelRef = useRef(null)
  const askedCancel = useRef(false)

  const fetchDetails = useCallback(() => {
    getAppointment(publicId)
      .then((body) => setAnswer({ data: body.data }))
      .catch((error) => {
        if (isSessionError(error)) onSessionExpired()
        setAnswer({ error })
      })
  }, [publicId, onSessionExpired])

  useEffect(() => {
    fetchDetails()
  }, [fetchDetails])

  // Asking "cancel?" moves focus to the safe choice; keeping the booking
  // brings it back to the button that asked, instead of losing it.
  useEffect(() => {
    if (confirmingCancel) {
      askedCancel.current = true
      keepRef.current?.focus()
    } else if (askedCancel.current) {
      askedCancel.current = false
      cancelRef.current?.focus()
    }
  }, [confirmingCancel])

  function reload() {
    setAnswer(null)
    fetchDetails()
  }

  async function run(action) {
    setBusy(action)
    setFeedback(null)
    try {
      const body = await runAppointmentAction(publicId, action)
      setAnswer({ data: body.data })
      setConfirmingCancel(false)
      if (action === 'resend_confirmation') {
        const outcome = ['sent', 'failed'].includes(body.meta?.attempt_status) ? body.meta.attempt_status : 'other'
        setFeedback({ kind: outcome === 'sent' ? 'success' : 'error', text: t(`agenda.details.resent.${outcome}`) })
      } else {
        setFeedback({ kind: 'success', text: body.meta?.changed === false ? t('agenda.details.done.unchanged') : t(`agenda.details.done.${action}`) })
        onChanged()
      }
    } catch (error) {
      if (isSessionError(error)) onSessionExpired()
      setFeedback({ kind: 'error', text: errorText(error, t) })
      // The reservation may have changed meanwhile (e.g. someone else
      // cancelled it): show what it is now, with its current actions.
      if (!isSessionError(error)) {
        getAppointment(publicId)
          .then((body) => setAnswer({ data: body.data }))
          .catch(() => {})
        onChanged()
      }
    } finally {
      setBusy(null)
    }
  }

  const data = answer?.data
  const timeZone = data?.timezone
  const allowed = data?.allowed_actions ?? []

  return (
    <Modal title={t('agenda.details.title')} closeLabel={t('agenda.details.close')} onClose={onClose} size="large">
      {answer === null && (
        <p role="status" className="agenda-muted">
          {t('agenda.details.loading')}
        </p>
      )}

      {answer?.error && (
        <div role="alert" className="notice notice--error">
          <p>{isSessionError(answer.error) ? t('agenda.sessionExpired') : t('agenda.details.error')}</p>
          {!isSessionError(answer.error) && (
            <button type="button" className="secondary-button" onClick={reload}>
              {t('agenda.retry')}
            </button>
          )}
        </div>
      )}

      {data && (
        <>
          <div className="details-header">
            <div>
              <p className="details-customer">{data.customer.name}</p>
              <p className="agenda-muted">
                {formatLongDate(zonedDate(data.starts_at, timeZone), i18n.language)} · {timeRange(data.starts_at, data.ends_at, timeZone)}
              </p>
            </div>
            <StatusBadge status={data.status} />
          </div>

          <dl className="details-list">
            <div>
              <dt>{t('agenda.details.service')}</dt>
              <dd>
                {data.service.name} · {t('booking.durationMinutes', { count: data.service.duration_minutes })} ·{' '}
                {formatPrice(data.service.price, i18n.language)}
              </dd>
            </div>
            <div>
              <dt>{t('agenda.details.professional')}</dt>
              <dd>{data.professional.name}</dd>
            </div>
            <div>
              <dt>{t('agenda.details.email')}</dt>
              <dd>{data.customer.email ?? <span className="agenda-muted">{t('agenda.details.noContact')}</span>}</dd>
            </div>
            <div>
              <dt>{t('agenda.details.phone')}</dt>
              <dd>{data.customer.phone ?? <span className="agenda-muted">{t('agenda.details.noContact')}</span>}</dd>
            </div>
            <div>
              <dt>{t('agenda.details.source')}</dt>
              <dd>{t(`agenda.source.${data.source}`)}</dd>
            </div>
            <div>
              <dt>{t('agenda.details.confirmation')}</dt>
              <dd>
                {t(`agenda.notification.${data.notification_status}`)}
                {data.notification && (
                  <span className="agenda-muted">
                    {' '}
                    · {t('agenda.details.attempts', { count: data.notification.attempts, max: data.notification.max_attempts })}
                    {data.notification.sent_at && <> · {t('agenda.details.sentAt', { time: formatTime(data.notification.sent_at, timeZone) })}</>}
                  </span>
                )}
              </dd>
            </div>
            <div>
              <dt>{t('agenda.details.reference')}</dt>
              <dd className="details-reference">{data.public_id}</dd>
            </div>
            {data.cancelled_at && data.cancelled_by && (
              <div>
                <dt>{t('agenda.details.status')}</dt>
                <dd>{t(`agenda.details.cancelledBy.${data.cancelled_by}`, { time: formatTime(data.cancelled_at, timeZone) })}</dd>
              </div>
            )}
          </dl>

          <div aria-live="polite">
            {feedback && (
              <p className={`notice notice--${feedback.kind}`} role={feedback.kind === 'error' ? 'alert' : 'status'}>
                {feedback.text}
              </p>
            )}
          </div>

          <section className="details-actions" aria-label={t('agenda.details.actionsTitle')}>
            {confirmingCancel ? (
              <div className="details-confirm" role="group" aria-labelledby="details-confirm-title">
                <p id="details-confirm-title" className="details-confirm-title">
                  {t('agenda.details.confirmCancelTitle')}
                </p>
                <p className="agenda-muted">{t('agenda.details.confirmCancelText')}</p>
                <div className="details-buttons">
                  <button ref={keepRef} type="button" className="secondary-button" onClick={() => setConfirmingCancel(false)} disabled={busy !== null}>
                    {t('agenda.details.keep')}
                  </button>
                  <button type="button" className="danger-button" onClick={() => run('cancel')} disabled={busy !== null} aria-busy={busy === 'cancel'}>
                    <BanIcon size={18} />
                    {busy === 'cancel' ? t('agenda.details.working') : t('agenda.details.confirmCancel')}
                  </button>
                </div>
              </div>
            ) : allowed.length === 0 ? (
              <p className="agenda-muted">{t('agenda.details.noActions')}</p>
            ) : (
              <div className="details-buttons">
                {STATUS_ACTIONS.filter(({ action }) => allowed.includes(action)).map(({ action, labelKey, className }) => (
                  <button key={action} type="button" className={className} onClick={() => run(action)} disabled={busy !== null} aria-busy={busy === action}>
                    {busy === action ? t('agenda.details.working') : t(labelKey)}
                  </button>
                ))}
                {allowed.includes('resend_confirmation') && (
                  <button
                    type="button"
                    className="secondary-button"
                    onClick={() => run('resend_confirmation')}
                    disabled={busy !== null}
                    aria-busy={busy === 'resend_confirmation'}
                  >
                    {busy === 'resend_confirmation' ? t('agenda.details.resending') : t('agenda.details.resend')}
                  </button>
                )}
                {allowed.includes('cancel') && (
                  <button
                    ref={cancelRef}
                    type="button"
                    className="danger-button"
                    onClick={() => {
                      setFeedback(null)
                      setConfirmingCancel(true)
                    }}
                    disabled={busy !== null}
                  >
                    <BanIcon size={18} />
                    {t('agenda.details.cancel')}
                  </button>
                )}
              </div>
            )}
          </section>
        </>
      )}
    </Modal>
  )
}
