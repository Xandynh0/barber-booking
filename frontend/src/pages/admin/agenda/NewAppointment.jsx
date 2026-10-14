import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { createAdminAppointment, getAdminAvailability } from '../../../api/agenda'
import { ApiError } from '../../../api/client'
import { Modal } from '../../../components/Modal'
import { formatTime } from '../../../utils/publicFormat'
import { errorText, isSessionError } from './agendaText'

function newKey() {
  return globalThis.crypto.randomUUID()
}

/**
 * "Novo agendamento" (`mode="scheduled"`) and "Atender agora"
 * (`mode="now"`). The start of "now" is never chosen here: the server takes
 * the current minute when it saves. Times for a scheduled booking come from
 * the admin availability (minimum notice ignored, working hours, blocks and
 * conflicts kept); the creation revalidates them under lock anyway.
 *
 * One Idempotency-Key per attempt: a retry of the exact same payload (after
 * a network failure or timeout) reuses it, any change gets a new one.
 * Everything typed survives a conflict, an error or a language switch.
 */
export function NewAppointment({ mode, date: initialDate, timeZone, services, professionals, defaultProfessionalId, onClose, onCreated, onSessionExpired }) {
  const { t } = useTranslation()
  const ids = { service: useId(), professional: useId(), date: useId(), time: useId(), name: useId(), email: useId(), phone: useId() }
  const isNow = mode === 'now'

  const activeServices = services.filter((service) => service.is_active)
  const [serviceId, setServiceId] = useState(activeServices.length === 1 ? String(activeServices[0].id) : '')
  const offering = professionals.filter(
    (professional) => professional.is_active && professional.services.some((service) => String(service.id) === serviceId)
  )
  const [professionalId, setProfessionalId] = useState(defaultProfessionalId ? String(defaultProfessionalId) : '')
  const effectiveProfessionalId = offering.some((professional) => String(professional.id) === professionalId) ? professionalId : ''

  const [date, setDate] = useState(initialDate)
  const [startsAt, setStartsAt] = useState('')
  const [customer, setCustomer] = useState({ customer_name: '', customer_email: '', customer_phone: '' })
  const [fieldErrors, setFieldErrors] = useState({})
  const [notice, setNotice] = useState(null) // { kind, text }
  const [submitting, setSubmitting] = useState(false)

  // Slots answer stored with the query it answers (see Booking.jsx).
  const [slotsAnswer, setSlotsAnswer] = useState(null)
  const slotsRequest = useRef(0)
  const attemptRef = useRef(null)
  const firstFieldRef = useRef(null)
  const formRef = useRef(null)
  // Bumped after a submit refused for its fields: focus then goes to the
  // first invalid field, so the error is where keyboard and screen reader are.
  const [invalidSubmit, setInvalidSubmit] = useState(0)

  useEffect(() => {
    if (invalidSubmit > 0) formRef.current?.querySelector('[aria-invalid="true"]:not(:disabled)')?.focus()
  }, [invalidSubmit])

  const slotsQuery = !isNow && serviceId && effectiveProfessionalId && date ? `${serviceId}|${effectiveProfessionalId}|${date}` : null

  const fetchSlots = useCallback((query) => {
    const request = ++slotsRequest.current
    const [service, professional, day] = query.split('|')
    getAdminAvailability({ serviceId: service, professionalId: professional, date: day })
      .then((body) => {
        if (request !== slotsRequest.current) return
        setSlotsAnswer({ query, slots: body.data.slots, failed: false })
        setStartsAt((current) => (body.data.slots.some((slot) => slot.starts_at === current) ? current : ''))
      })
      .catch((error) => {
        if (request !== slotsRequest.current) return
        if (isSessionError(error)) onSessionExpired()
        setSlotsAnswer({ query, slots: null, failed: true })
      })
  }, [onSessionExpired])

  useEffect(() => {
    if (slotsQuery) fetchSlots(slotsQuery)
    else slotsRequest.current++
  }, [slotsQuery, fetchSlots])

  const slotsCurrent = slotsAnswer !== null && slotsAnswer.query === slotsQuery
  const slots = slotsCurrent && !slotsAnswer.failed ? slotsAnswer.slots : null
  const slotsFailed = slotsCurrent && slotsAnswer.failed
  const effectiveStartsAt = slots?.some((slot) => slot.starts_at === startsAt) ? startsAt : ''

  function updateCustomer(field, value) {
    setCustomer((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => ({ ...current, [field]: undefined }))
  }

  function validate() {
    const errors = {}
    if (!serviceId) errors.service_id = [t('agenda.form.required')]
    if (!effectiveProfessionalId) errors.professional_id = [t('agenda.form.required')]
    if (!isNow && !effectiveStartsAt) errors.starts_at = [t('agenda.form.required')]
    if (customer.customer_name.trim() === '') errors.customer_name = [t('agenda.form.required')]
    setFieldErrors(errors)
    const valid = Object.keys(errors).length === 0
    if (!valid) setInvalidSubmit((count) => count + 1)
    return valid
  }

  async function submit(event) {
    event.preventDefault()
    if (submitting || !validate()) return

    const payload = {
      mode,
      service_id: Number(serviceId),
      professional_id: Number(effectiveProfessionalId),
      ...(isNow ? {} : { starts_at: effectiveStartsAt }),
      customer_name: customer.customer_name.trim(),
      customer_email: customer.customer_email.trim() || null,
      customer_phone: customer.customer_phone.trim() || null,
    }
    const fingerprint = JSON.stringify(payload)
    if (attemptRef.current?.fingerprint !== fingerprint) attemptRef.current = { fingerprint, key: newKey() }

    setSubmitting(true)
    setNotice(null)
    try {
      const body = await createAdminAppointment(payload, attemptRef.current.key)
      attemptRef.current = null
      onCreated(body.data)
    } catch (error) {
      handleError(error)
    } finally {
      setSubmitting(false)
    }
  }

  function handleError(error) {
    const code = error instanceof ApiError ? error.code : 'NETWORK_ERROR'

    if (isSessionError(error)) {
      onSessionExpired()
      setNotice({ kind: 'error', text: t('agenda.sessionExpired') })
      return
    }

    switch (code) {
      case 'SLOT_UNAVAILABLE':
        // Taken or blocked meanwhile: say why, refresh the times, keep
        // everything typed. Never move the booking to another time.
        setNotice({ kind: 'error', text: isNow ? error.message : `${error.message} ${t('agenda.form.slotRefreshed')}` })
        if (!isNow && slotsQuery) {
          setStartsAt('')
          setSlotsAnswer(null)
          fetchSlots(slotsQuery)
        }
        return
      case 'VALIDATION_ERROR':
        setFieldErrors(error.fields ?? {})
        setNotice({ kind: 'error', text: error.message })
        setInvalidSubmit((count) => count + 1)
        return
      case 'IDEMPOTENCY_KEY_REUSED':
        attemptRef.current = null
        setNotice({ kind: 'error', text: error.message })
        return
      case 'NETWORK_ERROR':
      case 'UNKNOWN_ERROR':
        setNotice({ kind: 'error', text: t('agenda.form.uncertain') })
        return
      default:
        if (error instanceof ApiError && error.status >= 500) {
          setNotice({ kind: 'error', text: t('agenda.form.uncertain') })
          return
        }
        setNotice({ kind: 'error', text: errorText(error, t) })
    }
  }

  function fieldError(name) {
    const messages = fieldErrors[name]
    return messages && messages.length > 0 ? messages[0] : null
  }

  return (
    <Modal
      title={isNow ? t('agenda.form.nowTitle') : t('agenda.form.newTitle')}
      closeLabel={t('agenda.form.cancel')}
      onClose={onClose}
      initialFocusRef={firstFieldRef}
    >
      <form ref={formRef} className="agenda-form" onSubmit={submit} noValidate>
        {isNow && (
          <div className="notice notice--info agenda-info">
            <p>{t('agenda.form.nowHint')}</p>
            <p>{t('agenda.form.nowNoEmail')}</p>
          </div>
        )}

        <div className="field">
          <label htmlFor={ids.service}>{t('agenda.form.service')}</label>
          <select
            id={ids.service}
            ref={firstFieldRef}
            value={serviceId}
            onChange={(event) => {
              setServiceId(event.target.value)
              setFieldErrors((current) => ({ ...current, service_id: undefined }))
            }}
            aria-invalid={fieldError('service_id') ? 'true' : undefined}
            aria-describedby={fieldError('service_id') ? `${ids.service}-error` : undefined}
          >
            <option value="">{t('agenda.form.chooseService')}</option>
            {activeServices.map((service) => (
              <option key={service.id} value={service.id}>
                {service.name} · {t('booking.durationMinutes', { count: service.duration_minutes })}
              </option>
            ))}
          </select>
          {fieldError('service_id') && (
            <p id={`${ids.service}-error`} className="field-error">
              {fieldError('service_id')}
            </p>
          )}
        </div>

        <div className="field">
          <label htmlFor={ids.professional}>{t('agenda.form.professional')}</label>
          <select
            id={ids.professional}
            value={effectiveProfessionalId}
            onChange={(event) => {
              setProfessionalId(event.target.value)
              setFieldErrors((current) => ({ ...current, professional_id: undefined }))
            }}
            disabled={!serviceId}
            aria-invalid={fieldError('professional_id') ? 'true' : undefined}
            aria-describedby={fieldError('professional_id') ? `${ids.professional}-error` : undefined}
          >
            <option value="">{t('agenda.form.chooseProfessional')}</option>
            {offering.map((professional) => (
              <option key={professional.id} value={professional.id}>
                {professional.name}
              </option>
            ))}
          </select>
          {serviceId && offering.length === 0 && <p className="agenda-muted">{t('agenda.form.noProfessionalForService')}</p>}
          {fieldError('professional_id') && (
            <p id={`${ids.professional}-error`} className="field-error">
              {fieldError('professional_id')}
            </p>
          )}
        </div>

        {!isNow && (
          <div className="agenda-form-row">
            <div className="field">
              <label htmlFor={ids.date}>{t('agenda.form.date')}</label>
              <input id={ids.date} type="date" value={date} onChange={(event) => setDate(event.target.value)} required />
            </div>

            <div className="field">
              <label htmlFor={ids.time}>{t('agenda.form.time')}</label>
              <select
                id={ids.time}
                value={effectiveStartsAt}
                onChange={(event) => {
                  setStartsAt(event.target.value)
                  setFieldErrors((current) => ({ ...current, starts_at: undefined }))
                }}
                disabled={!slots || slots.length === 0}
                aria-invalid={fieldError('starts_at') ? 'true' : undefined}
                aria-describedby={fieldError('starts_at') ? `${ids.time}-error` : `${ids.time}-hint`}
              >
                <option value="">{t('agenda.form.chooseTime')}</option>
                {(slots ?? []).map((slot) => (
                  <option key={slot.starts_at} value={slot.starts_at}>
                    {formatTime(slot.starts_at, timeZone)}
                  </option>
                ))}
              </select>
              <p id={`${ids.time}-hint`} className="agenda-muted" role="status">
                {slotsQuery && !slotsCurrent && t('agenda.form.loadingSlots')}
                {slots && slots.length === 0 && t('agenda.form.noSlots')}
              </p>
              {slotsFailed && (
                <p className="field-error">
                  {t('agenda.form.slotsError')}{' '}
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => {
                      setSlotsAnswer(null)
                      fetchSlots(slotsQuery)
                    }}
                  >
                    {t('agenda.retry')}
                  </button>
                </p>
              )}
              {fieldError('starts_at') && (
                <p id={`${ids.time}-error`} className="field-error">
                  {fieldError('starts_at')}
                </p>
              )}
            </div>
          </div>
        )}

        {[
          { field: 'customer_name', id: ids.name, label: t('agenda.form.customerName'), type: 'text', autoComplete: 'off', required: true },
          { field: 'customer_email', id: ids.email, label: t('agenda.form.customerEmail'), type: 'email', autoComplete: 'off', hint: isNow ? null : t('agenda.form.emailHint') },
          { field: 'customer_phone', id: ids.phone, label: t('agenda.form.customerPhone'), type: 'tel', autoComplete: 'off' },
        ].map(({ field, id, label, type, autoComplete, required, hint }) => (
          <div key={field} className="field">
            <label htmlFor={id}>{label}</label>
            <input
              id={id}
              type={type}
              value={customer[field]}
              onChange={(event) => updateCustomer(field, event.target.value)}
              autoComplete={autoComplete}
              required={required}
              aria-invalid={fieldError(field) ? 'true' : undefined}
              aria-describedby={[fieldError(field) ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined}
            />
            {hint && (
              <p id={`${id}-hint`} className="agenda-muted">
                {hint}
              </p>
            )}
            {fieldError(field) && (
              <p id={`${id}-error`} className="field-error">
                {fieldError(field)}
              </p>
            )}
          </div>
        ))}

        <div aria-live="polite">
          {notice && (
            <p className={`notice notice--${notice.kind}`} role="alert">
              {notice.text}
            </p>
          )}
        </div>

        <div className="details-buttons agenda-form-actions">
          <button type="button" className="secondary-button" onClick={onClose}>
            {t('agenda.form.cancel')}
          </button>
          <button type="submit" className="primary-button" disabled={submitting} aria-busy={submitting}>
            {submitting ? t('agenda.form.submitting') : isNow ? t('agenda.form.submitNow') : t('agenda.form.submit')}
          </button>
        </div>
      </form>
    </Modal>
  )
}
