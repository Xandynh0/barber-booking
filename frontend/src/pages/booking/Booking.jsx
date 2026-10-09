import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import {
  createAppointment,
  getAvailability,
  getBusiness,
  listPublicServices,
  listServiceProfessionals,
} from '../../api/publicBooking'
import { LanguageSwitcher } from '../../components/LanguageSwitcher'
import { formatLongDate, formatDateLabel, formatPrice, formatTime, horizonDates, zonedDate } from '../../utils/publicFormat'
import '../../App.css'
import '../public.css'

const STEPS = ['service', 'professional', 'datetime', 'customer', 'review']

const CUSTOMER_FIELDS = ['customer_name', 'customer_email', 'customer_phone']

/**
 * Generates a key for one logical booking attempt. The same key is reused
 * for every retry of the exact same payload (double click, timeout,
 * network failure); any change to the payload gets a new one
 * (docs/planejamento-barbearia-mvp.md, seção 11).
 */
function newIdempotencyKey() {
  return globalThis.crypto.randomUUID()
}

/**
 * Public booking journey: service → professional → day/time → customer
 * details → review → confirmation (docs/planejamento-barbearia-mvp.md,
 * seção 2). All state lives in memory only — nothing personal is written to
 * localStorage — so it survives going back between steps and switching the
 * language (which never remounts this page). Availability is always the
 * API's answer; the client never recomputes booking rules.
 */
function Booking() {
  const { t, i18n } = useTranslation()
  const [searchParams] = useSearchParams()
  const language = i18n.language

  const [step, setStep] = useState('service')
  const [business, setBusiness] = useState(null)

  const [services, setServices] = useState(null)
  const [servicesFailed, setServicesFailed] = useState(false)
  const [serviceId, setServiceId] = useState(() => {
    const preselected = Number(searchParams.get('servico'))
    return Number.isInteger(preselected) && preselected > 0 ? preselected : null
  })

  const [professionals, setProfessionals] = useState(null)
  const [professionalsFailed, setProfessionalsFailed] = useState(false)
  const [professionalId, setProfessionalId] = useState(null)

  const [date, setDate] = useState(null)
  const [slots, setSlots] = useState(null)
  const [slotsFailed, setSlotsFailed] = useState(false)
  const [startsAt, setStartsAt] = useState(null)

  const [customer, setCustomer] = useState({ customer_name: '', customer_email: '', customer_phone: '' })
  const [fieldErrors, setFieldErrors] = useState({})

  const [submitting, setSubmitting] = useState(false)
  const [notice, setNotice] = useState(null) // { kind: 'error' | 'info', key, retryable }
  const [result, setResult] = useState(null)

  const attemptRef = useRef(null) // { fingerprint, key }
  const professionalsRequest = useRef(0)
  const slotsRequest = useRef(0)
  const headingRef = useRef(null)
  const isFirstRender = useRef(true)
  const ids = { name: useId(), email: useId(), phone: useId() }

  // --- Catalog ---------------------------------------------------------

  const loadCatalog = useCallback(() => {
    setServicesFailed(false)
    setServices(null)
    getBusiness()
      .then((body) => setBusiness(body.data))
      .catch(() => setServicesFailed(true))
    listPublicServices()
      .then((body) => setServices(body.data))
      .catch(() => setServicesFailed(true))
  }, [])

  useEffect(() => {
    loadCatalog()
  }, [loadCatalog])

  // A preselected service (from the homepage) that is not bookable anymore
  // is simply dropped.
  useEffect(() => {
    if (services && serviceId !== null && !services.some((service) => service.id === serviceId)) {
      setServiceId(null)
    }
  }, [services, serviceId])

  const loadProfessionals = useCallback((forServiceId) => {
    const request = ++professionalsRequest.current
    setProfessionals(null)
    setProfessionalsFailed(false)

    listServiceProfessionals(forServiceId)
      .then((body) => {
        if (request === professionalsRequest.current) setProfessionals(body.data)
      })
      .catch(() => {
        if (request === professionalsRequest.current) setProfessionalsFailed(true)
      })
  }, [])

  useEffect(() => {
    if (serviceId !== null) {
      loadProfessionals(serviceId)
    } else {
      professionalsRequest.current++
      setProfessionals(null)
    }
  }, [serviceId, loadProfessionals])

  // --- Availability ------------------------------------------------------

  /**
   * Only the latest request may update the slots: a slower response for a
   * previous day/professional is ignored when it finally arrives.
   */
  const loadSlots = useCallback((query) => {
    const request = ++slotsRequest.current
    setSlots(null)
    setSlotsFailed(false)

    getAvailability(query)
      .then((body) => {
        if (request !== slotsRequest.current) return
        setSlots(body.data.slots)
        // A selected time that is not in the current answer is dropped.
        setStartsAt((current) =>
          current && body.data.slots.some((slot) => slot.starts_at === current) ? current : null
        )
      })
      .catch(() => {
        if (request === slotsRequest.current) setSlotsFailed(true)
      })
  }, [])

  useEffect(() => {
    if (serviceId !== null && professionalId !== null && date !== null) {
      loadSlots({ serviceId, professionalId, date })
    } else {
      slotsRequest.current++
      setSlots(null)
    }
  }, [serviceId, professionalId, date, loadSlots])

  // --- Choices and their dependents --------------------------------------

  function chooseService(id) {
    if (id === serviceId) return
    setServiceId(id)
    setProfessionalId(null)
    setStartsAt(null)
  }

  function chooseProfessional(id) {
    if (id === professionalId) return
    setProfessionalId(id)
    setStartsAt(null)
  }

  function chooseDate(value) {
    if (value === date) return
    setDate(value)
    setStartsAt(null)
  }

  function updateCustomer(field, value) {
    setCustomer((current) => ({ ...current, [field]: value }))
    setFieldErrors((current) => ({ ...current, [field]: undefined }))
  }

  // --- Navigation ----------------------------------------------------------

  const dates = useMemo(
    () => (business ? horizonDates(business.timezone, business.booking_horizon_days) : []),
    [business]
  )

  function goTo(nextStep) {
    setNotice(null)
    setStep(nextStep)
    if (nextStep === 'datetime' && date === null && dates.length > 0) {
      setDate(dates[0])
    }
  }

  useEffect(() => {
    // Move focus to the new step's heading so keyboard and screen-reader
    // users land on it (not on the first render).
    if (isFirstRender.current) {
      isFirstRender.current = false
      return
    }
    headingRef.current?.focus()
  }, [step])

  function validateCustomer() {
    const errors = {}
    for (const field of CUSTOMER_FIELDS) {
      if (customer[field].trim() === '') errors[field] = [t('booking.customer.required')]
    }
    setFieldErrors(errors)
    return Object.keys(errors).length === 0
  }

  const canContinue = {
    service: serviceId !== null,
    professional: professionalId !== null,
    datetime: startsAt !== null,
    customer: true,
  }

  function next() {
    if (step === 'customer' && !validateCustomer()) return
    goTo(STEPS[STEPS.indexOf(step) + 1])
  }

  function back() {
    goTo(STEPS[STEPS.indexOf(step) - 1])
  }

  // --- Confirmation --------------------------------------------------------

  async function confirm() {
    if (submitting) return

    const payload = {
      service_id: serviceId,
      professional_id: professionalId,
      starts_at: startsAt,
      customer_name: customer.customer_name.trim(),
      customer_email: customer.customer_email.trim(),
      customer_phone: customer.customer_phone.trim(),
    }
    const fingerprint = JSON.stringify(payload)
    if (attemptRef.current?.fingerprint !== fingerprint) {
      attemptRef.current = { fingerprint, key: newIdempotencyKey() }
    }

    setSubmitting(true)
    setNotice(null)

    try {
      const body = await createAppointment(payload, attemptRef.current.key)
      attemptRef.current = null
      setResult(body.data)
      setStep('done')
    } catch (error) {
      handleConfirmError(error)
    } finally {
      setSubmitting(false)
    }
  }

  function handleConfirmError(error) {
    const code = error instanceof ApiError ? error.code : 'NETWORK_ERROR'

    switch (code) {
      case 'SLOT_UNAVAILABLE':
        // Someone else got there first (or the time stopped being valid):
        // refresh the times, keep everything the customer typed.
        setStartsAt(null)
        setStep('datetime')
        setNotice({ kind: 'error', key: 'booking.errors.slotTaken' })
        loadSlots({ serviceId, professionalId, date })
        return
      case 'VALIDATION_ERROR': {
        const fields = error.fields ?? {}
        if (CUSTOMER_FIELDS.some((field) => fields[field])) {
          setFieldErrors(fields)
          setStep('customer')
          setNotice({ kind: 'error', key: 'booking.errors.validation' })
        } else {
          setStep('service')
          setNotice({ kind: 'error', key: 'booking.errors.selectionInvalid' })
          loadCatalog()
        }
        return
      }
      case 'CONTACT_LIMIT_REACHED':
        setNotice({ kind: 'error', key: 'booking.errors.contactLimit' })
        return
      case 'RATE_LIMITED':
        setNotice({ kind: 'error', key: 'booking.errors.rateLimited', retryable: true })
        return
      case 'IDEMPOTENCY_KEY_REUSED':
        attemptRef.current = null
        setNotice({ kind: 'error', key: 'booking.errors.keyReused', retryable: true })
        return
      case 'SESSION_EXPIRED':
        setNotice({ kind: 'error', key: 'booking.errors.sessionExpired', retryable: true })
        return
      default:
        // Network failure, timeout, 5xx: the reservation may or may not have
        // been saved. Retrying with the same key returns it if it was.
        setNotice({ kind: 'error', key: 'booking.errors.uncertain', retryable: true })
    }
  }

  function startOver() {
    attemptRef.current = null
    setResult(null)
    setServiceId(null)
    setProfessionalId(null)
    setDate(null)
    setStartsAt(null)
    setCustomer({ customer_name: '', customer_email: '', customer_phone: '' })
    setFieldErrors({})
    setNotice(null)
    setStep('service')
  }

  // --- Rendering -----------------------------------------------------------

  const service = services?.find((item) => item.id === serviceId) ?? null
  const professional = professionals?.find((item) => item.id === professionalId) ?? null
  const timezone = business?.timezone
  const stepIndex = STEPS.indexOf(step)
  const shopName = business?.name || t('common.brand')

  const noticeElement = notice && (
    <div className={`public-notice public-notice--${notice.kind}`} role="alert">
      <p>{t(notice.key)}</p>
      {notice.retryable && step === 'review' && (
        <button type="button" className="secondary-button" onClick={confirm} disabled={submitting}>
          {t('booking.actions.tryConfirmAgain')}
        </button>
      )}
    </div>
  )

  return (
    <main className="booking-page">
      <header className="booking-header">
        <Link to="/" className="booking-brand">
          {shopName}
        </Link>
        {business?.address && <span className="booking-header-meta">{business.address}</span>}
        <LanguageSwitcher />
      </header>

      {step === 'done' && result ? (
        <Done result={result} customerEmail={customer.customer_email.trim()} timezone={timezone} onStartOver={startOver} headingRef={headingRef} />
      ) : (
        <>
          <ol className="booking-steps" aria-label={t('booking.stepsLabel')}>
            {STEPS.map((name, index) => (
              <li key={name} className="booking-step" aria-current={name === step ? 'step' : undefined} data-done={index < stepIndex || undefined}>
                <span className="booking-step-number" aria-hidden="true">{index + 1}</span>
                {t(`booking.steps.${name}`)}
              </li>
            ))}
          </ol>

          <div className="booking-layout">
            <section className="booking-main" aria-labelledby="booking-step-heading">
              <p className="booking-step-of">{t('booking.stepOf', { current: stepIndex + 1, total: STEPS.length })}</p>
              <h1 id="booking-step-heading" ref={headingRef} tabIndex={-1} className="booking-heading">
                {t(`booking.${step}.heading`)}
              </h1>

              {noticeElement}

              {step === 'service' && (
                <OptionList
                  items={services}
                  failed={servicesFailed}
                  onRetry={loadCatalog}
                  selectedId={serviceId}
                  onSelect={chooseService}
                  labels={{ loading: 'booking.service.loading', empty: 'booking.service.empty', error: 'booking.service.error' }}
                  renderItem={(item) => (
                    <>
                      <span className="option-title">{item.name}</span>
                      {item.description && <span className="option-description">{item.description}</span>}
                      <span className="option-meta">
                        {t('booking.durationMinutes', { count: item.duration_minutes })} · {formatPrice(item.price, language)}
                      </span>
                    </>
                  )}
                />
              )}

              {step === 'professional' && (
                <OptionList
                  items={professionals}
                  failed={professionalsFailed}
                  onRetry={() => loadProfessionals(serviceId)}
                  selectedId={professionalId}
                  onSelect={chooseProfessional}
                  labels={{ loading: 'booking.professional.loading', empty: 'booking.professional.empty', error: 'booking.professional.error' }}
                  renderItem={(item) => (
                    <>
                      <span className="option-title">{item.name}</span>
                      {item.description && <span className="option-description">{item.description}</span>}
                    </>
                  )}
                />
              )}

              {step === 'datetime' && (
                <DateTimeStep
                  dates={dates}
                  date={date}
                  onDate={chooseDate}
                  slots={slots}
                  failed={slotsFailed}
                  onRetry={() => loadSlots({ serviceId, professionalId, date })}
                  startsAt={startsAt}
                  onSlot={setStartsAt}
                  timezone={timezone}
                  language={language}
                />
              )}

              {step === 'customer' && (
                <div className="booking-form">
                  <p>{t('booking.customer.intro')}</p>
                  <Field id={ids.name} label={t('booking.customer.name')} error={fieldErrors.customer_name}>
                    <input id={ids.name} type="text" autoComplete="name" maxLength={120} value={customer.customer_name} onChange={(event) => updateCustomer('customer_name', event.target.value)} aria-invalid={Boolean(fieldErrors.customer_name)} aria-describedby={fieldErrors.customer_name ? `${ids.name}-error` : undefined} />
                  </Field>
                  <Field id={ids.email} label={t('booking.customer.email')} error={fieldErrors.customer_email}>
                    <input id={ids.email} type="email" autoComplete="email" maxLength={254} value={customer.customer_email} onChange={(event) => updateCustomer('customer_email', event.target.value)} aria-invalid={Boolean(fieldErrors.customer_email)} aria-describedby={fieldErrors.customer_email ? `${ids.email}-error` : undefined} />
                  </Field>
                  <Field id={ids.phone} label={t('booking.customer.phone')} hint={t('booking.customer.phoneHint')} error={fieldErrors.customer_phone}>
                    <input id={ids.phone} type="tel" autoComplete="tel" maxLength={32} value={customer.customer_phone} onChange={(event) => updateCustomer('customer_phone', event.target.value)} aria-invalid={Boolean(fieldErrors.customer_phone)} aria-describedby={`${ids.phone}-hint${fieldErrors.customer_phone ? ` ${ids.phone}-error` : ''}`} />
                  </Field>
                </div>
              )}

              {step === 'review' && (
                <div className="booking-review">
                  <h2 className="booking-subheading">{t('booking.review.customerTitle')}</h2>
                  <dl className="summary-list">
                    <dt>{t('booking.customer.name')}</dt>
                    <dd>{customer.customer_name.trim()}</dd>
                    <dt>{t('booking.customer.email')}</dt>
                    <dd>{customer.customer_email.trim()}</dd>
                    <dt>{t('booking.customer.phone')}</dt>
                    <dd>{customer.customer_phone.trim()}</dd>
                  </dl>
                  <p className="booking-hint">{t('booking.review.note')}</p>
                </div>
              )}

              <div className="booking-actions">
                {stepIndex > 0 && (
                  <button type="button" className="secondary-button" onClick={back} disabled={submitting}>
                    {t('booking.actions.back')}
                  </button>
                )}
                {step === 'review' ? (
                  <button type="button" className="primary-button" onClick={confirm} disabled={submitting} aria-busy={submitting}>
                    {submitting ? t('booking.actions.confirming') : t('booking.actions.confirm')}
                  </button>
                ) : (
                  <button type="button" className="primary-button" onClick={next} disabled={!canContinue[step]}>
                    {t('booking.actions.continue')}
                  </button>
                )}
              </div>
            </section>

            <Summary service={service} professional={professional} startsAt={startsAt} timezone={timezone} language={language} />
          </div>
        </>
      )}
    </main>
  )
}

function OptionList({ items, failed, onRetry, selectedId, onSelect, labels, renderItem }) {
  const { t } = useTranslation()

  if (failed) {
    return (
      <div role="alert" className="public-notice public-notice--error">
        <p>{t(labels.error)}</p>
        <button type="button" className="secondary-button" onClick={onRetry}>
          {t('booking.actions.retry')}
        </button>
      </div>
    )
  }
  if (items === null) return <p role="status">{t(labels.loading)}</p>
  if (items.length === 0) return <p>{t(labels.empty)}</p>

  return (
    <ul className="option-list">
      {items.map((item) => (
        <li key={item.id}>
          <button type="button" className="option-card" aria-pressed={item.id === selectedId} onClick={() => onSelect(item.id)}>
            {renderItem(item)}
          </button>
        </li>
      ))}
    </ul>
  )
}

function DateTimeStep({ dates, date, onDate, slots, failed, onRetry, startsAt, onSlot, timezone, language }) {
  const { t } = useTranslation()

  return (
    <div className="booking-datetime">
      <h2 className="booking-subheading" id="booking-dates-label">
        {t('booking.datetime.datesLabel')}
      </h2>
      <ul className="date-list" aria-labelledby="booking-dates-label">
        {dates.map((value) => (
          <li key={value}>
            <button type="button" className="date-chip" aria-pressed={value === date} onClick={() => onDate(value)}>
              {formatDateLabel(value, language)}
            </button>
          </li>
        ))}
      </ul>

      {date === null && <p>{t('booking.datetime.pickDate')}</p>}

      {date !== null && (
        <>
          <h2 className="booking-subheading" id="booking-slots-label">
            {t('booking.datetime.slotsLabel', { date: formatLongDate(date, language) })}
          </h2>
          {timezone && <p className="booking-hint">{t('booking.datetime.timezoneNote', { timezone })}</p>}

          {failed && (
            <div role="alert" className="public-notice public-notice--error">
              <p>{t('booking.datetime.error')}</p>
              <button type="button" className="secondary-button" onClick={onRetry}>
                {t('booking.actions.retry')}
              </button>
            </div>
          )}
          {!failed && slots === null && <p role="status">{t('booking.datetime.loading')}</p>}
          {!failed && slots !== null && slots.length === 0 && <p role="status">{t('booking.datetime.empty')}</p>}
          {!failed && slots !== null && slots.length > 0 && (
            <ul className="slot-list" aria-labelledby="booking-slots-label">
              {slots.map((slot) => (
                <li key={slot.starts_at}>
                  <button type="button" className="slot-chip" aria-pressed={slot.starts_at === startsAt} onClick={() => onSlot(slot.starts_at)}>
                    {formatTime(slot.starts_at, timezone)}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}

function Field({ id, label, hint, error, children }) {
  return (
    <div className="booking-field">
      <label htmlFor={id}>{label}</label>
      {hint && (
        <span className="booking-hint" id={`${id}-hint`}>
          {hint}
        </span>
      )}
      {children}
      {error && (
        <span className="field-error" id={`${id}-error`}>
          {error[0]}
        </span>
      )}
    </div>
  )
}

function Summary({ service, professional, startsAt, timezone, language }) {
  const { t } = useTranslation()
  const toChoose = <span className="summary-pending">{t('booking.summary.toChoose')}</span>

  return (
    <aside className="booking-summary" aria-labelledby="booking-summary-title">
      <h2 id="booking-summary-title" className="booking-subheading">
        {t('booking.summary.title')}
      </h2>
      <dl className="summary-list">
        <dt>{t('booking.summary.service')}</dt>
        <dd>{service ? service.name : toChoose}</dd>
        <dt>{t('booking.summary.duration')}</dt>
        <dd>{service ? t('booking.durationMinutes', { count: service.duration_minutes }) : toChoose}</dd>
        <dt>{t('booking.summary.professional')}</dt>
        <dd>{professional ? professional.name : toChoose}</dd>
        <dt>{t('booking.summary.date')}</dt>
        <dd>{startsAt && timezone ? formatLongDate(zonedDate(startsAt, timezone), language) : toChoose}</dd>
        <dt>{t('booking.summary.time')}</dt>
        <dd>{startsAt && timezone ? formatTime(startsAt, timezone) : toChoose}</dd>
        <dt>{t('booking.summary.price')}</dt>
        <dd>{service ? formatPrice(service.price, language) : toChoose}</dd>
      </dl>
    </aside>
  )
}

/**
 * The reservation is confirmed as soon as the API answered 201/200 — the
 * e-mail is a separate, best-effort step: its status is reported as is,
 * never as "delivered".
 */
function Done({ result, customerEmail, timezone, onStartOver, headingRef }) {
  const { t, i18n } = useTranslation()
  const zone = result.timezone || timezone
  const emailKey = {
    sent: 'booking.done.emailSent',
    skipped: 'booking.done.emailSkipped',
  }[result.notification_status] ?? 'booking.done.emailNotSent'

  return (
    <section className="booking-done" aria-labelledby="booking-done-heading">
      <h1 id="booking-done-heading" ref={headingRef} tabIndex={-1} className="booking-heading">
        {t('booking.done.heading')}
      </h1>
      <p role="status">{t('booking.done.intro')}</p>

      <dl className="summary-list">
        <dt>{t('booking.summary.service')}</dt>
        <dd>{result.service.name}</dd>
        <dt>{t('booking.summary.professional')}</dt>
        <dd>{result.professional.name}</dd>
        <dt>{t('booking.summary.date')}</dt>
        <dd>{formatLongDate(zonedDate(result.starts_at, zone), i18n.language)}</dd>
        <dt>{t('booking.summary.time')}</dt>
        <dd>
          {formatTime(result.starts_at, zone)}–{formatTime(result.ends_at, zone)}
        </dd>
        <dt>{t('booking.summary.duration')}</dt>
        <dd>{t('booking.durationMinutes', { count: result.service.duration_minutes })}</dd>
        <dt>{t('booking.summary.price')}</dt>
        <dd>{formatPrice(result.service.price, i18n.language)}</dd>
        <dt>{t('booking.done.reference')}</dt>
        <dd className="summary-reference">{result.public_id}</dd>
      </dl>

      <p className={`public-notice ${result.notification_status === 'sent' ? '' : 'public-notice--info'}`}>
        {t(emailKey, { email: customerEmail })}
      </p>

      <div className="booking-actions">
        <Link to="/" className="secondary-button">
          {t('booking.backHome')}
        </Link>
        <button type="button" className="primary-button" onClick={onStartOver}>
          {t('booking.actions.newBooking')}
        </button>
      </div>
    </section>
  )
}

export default Booking
