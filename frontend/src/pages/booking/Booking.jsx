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
import { PublicHeader } from '../../components/PublicHeader'
import {
  ArrowRightIcon,
  CalendarIcon,
  CheckIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  MailIcon,
  ScissorsIcon,
  UserIcon,
} from '../../components/PublicIcons'
import {
  formatDateCard,
  formatDayMonth,
  formatLongDate,
  formatPrice,
  formatTime,
  formatWeekday,
  horizonDates,
  zonedDate,
} from '../../utils/publicFormat'
import '../../styles/barber-theme.css'
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

  // Answers are stored with the query they answer; a different (or no)
  // query reads as "loading", so changing a choice needs no reset.
  const [professionalsAnswer, setProfessionalsAnswer] = useState(null) // { query, data, failed }
  const [professionalId, setProfessionalId] = useState(null)

  const [date, setDate] = useState(null)
  const [slotsAnswer, setSlotsAnswer] = useState(null) // { query, data, failed }
  const [startsAt, setStartsAt] = useState(null)
  // Days the API already answered with no slots, for the current service and
  // professional — only to mark them in the date carousel.
  const [emptyDays, setEmptyDays] = useState(() => new Set())

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
  //
  // Fetchers only set state once the API answers; "back to loading" for a
  // retry is set by the event that asks for it (react-hooks
  // set-state-in-effect).

  const fetchCatalog = useCallback(() => {
    getBusiness()
      .then((body) => setBusiness(body.data))
      .catch(() => setServicesFailed(true))
    listPublicServices()
      .then((body) => {
        setServices(body.data)
        // A preselected service (from the homepage) that is not bookable
        // anymore is simply dropped.
        setServiceId((current) =>
          current !== null && !body.data.some((item) => item.id === current) ? null : current
        )
      })
      .catch(() => setServicesFailed(true))
  }, [])

  useEffect(() => {
    fetchCatalog()
  }, [fetchCatalog])

  function reloadCatalog() {
    setServicesFailed(false)
    setServices(null)
    fetchCatalog()
  }

  const fetchProfessionals = useCallback((forServiceId) => {
    const request = ++professionalsRequest.current
    listServiceProfessionals(forServiceId)
      .then((body) => {
        if (request === professionalsRequest.current) {
          setProfessionalsAnswer({ query: forServiceId, data: body.data, failed: false })
        }
      })
      .catch(() => {
        if (request === professionalsRequest.current) {
          setProfessionalsAnswer({ query: forServiceId, data: null, failed: true })
        }
      })
  }, [])

  useEffect(() => {
    if (serviceId !== null) {
      fetchProfessionals(serviceId)
    } else {
      professionalsRequest.current++
    }
  }, [serviceId, fetchProfessionals])

  function reloadProfessionals() {
    setProfessionalsAnswer(null)
    fetchProfessionals(serviceId)
  }

  const professionalsCurrent = professionalsAnswer !== null && professionalsAnswer.query === serviceId
  const professionals = professionalsCurrent && !professionalsAnswer.failed ? professionalsAnswer.data : null
  const professionalsFailed = professionalsCurrent && professionalsAnswer.failed

  // --- Availability ------------------------------------------------------

  const slotsQuery =
    serviceId !== null && professionalId !== null && date !== null ? `${serviceId}|${professionalId}|${date}` : null

  /**
   * Only the latest request may update the slots: a slower response for a
   * previous day/professional is ignored when it finally arrives.
   */
  const fetchSlots = useCallback((query) => {
    const request = ++slotsRequest.current
    const key = `${query.serviceId}|${query.professionalId}|${query.date}`

    getAvailability(query)
      .then((body) => {
        if (request !== slotsRequest.current) return
        setSlotsAnswer({ query: key, data: body.data.slots, failed: false })
        setEmptyDays((current) => {
          const next = new Set(current)
          if (body.data.slots.length === 0) next.add(query.date)
          else next.delete(query.date)
          return next
        })
        // A selected time that is not in the current answer is dropped.
        setStartsAt((current) =>
          current && body.data.slots.some((slot) => slot.starts_at === current) ? current : null
        )
      })
      .catch(() => {
        if (request === slotsRequest.current) setSlotsAnswer({ query: key, data: null, failed: true })
      })
  }, [])

  useEffect(() => {
    if (serviceId !== null && professionalId !== null && date !== null) {
      fetchSlots({ serviceId, professionalId, date })
    } else {
      slotsRequest.current++
    }
  }, [serviceId, professionalId, date, fetchSlots])

  function reloadSlots() {
    setSlotsAnswer(null)
    fetchSlots({ serviceId, professionalId, date })
  }

  const slotsCurrent = slotsAnswer !== null && slotsQuery !== null && slotsAnswer.query === slotsQuery
  const slots = slotsCurrent && !slotsAnswer.failed ? slotsAnswer.data : null
  const slotsFailed = slotsCurrent && slotsAnswer.failed

  // --- Choices and their dependents --------------------------------------

  function chooseService(id) {
    if (id === serviceId) return
    setServiceId(id)
    setProfessionalId(null)
    setStartsAt(null)
    setEmptyDays(new Set())
  }

  function chooseProfessional(id) {
    if (id === professionalId) return
    setProfessionalId(id)
    setStartsAt(null)
    setEmptyDays(new Set())
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
        reloadSlots()
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
          reloadCatalog()
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
    <div className="public-shell barber-theme">
      <PublicHeader shopName={shopName} />

      <main className="booking-page">
        {step === 'done' && result ? (
          <Done result={result} customerEmail={customer.customer_email.trim()} timezone={timezone} onStartOver={startOver} headingRef={headingRef} />
        ) : (
          <div className="booking-card theme-card">
            <ol className="booking-steps" aria-label={t('booking.stepsLabel')}>
              {STEPS.map((name, index) => {
                const done = index < stepIndex
                return (
                  <li key={name} className="booking-step" aria-current={name === step ? 'step' : undefined} data-done={done || undefined}>
                    <span className="booking-step-marker" aria-hidden="true">
                      {done ? <CheckIcon size={16} /> : index + 1}
                    </span>
                    <span className="booking-step-label">{t(`booking.steps.${name}`)}</span>
                  </li>
                )
              })}
            </ol>

            <div className="booking-layout">
              <section className="booking-main" aria-labelledby="booking-step-heading">
                <span className="sr-only">{t('booking.stepOf', { current: stepIndex + 1, total: STEPS.length })}</span>
                <h1 id="booking-step-heading" ref={headingRef} tabIndex={-1} className="booking-heading">
                  {t(`booking.${step}.heading`)}
                </h1>
                <p className="booking-intro">{t(`booking.${step}.intro`)}</p>

                {noticeElement}

                {step === 'service' && (
                  <OptionList
                    items={services}
                    failed={servicesFailed}
                    onRetry={reloadCatalog}
                    selectedId={serviceId}
                    onSelect={chooseService}
                    icon={<ScissorsIcon size={22} />}
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
                    onRetry={reloadProfessionals}
                    selectedId={professionalId}
                    onSelect={chooseProfessional}
                    icon={<UserIcon size={22} />}
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
                    emptyDays={emptyDays}
                    slots={slots}
                    failed={slotsFailed}
                    onRetry={reloadSlots}
                    startsAt={startsAt}
                    onSlot={setStartsAt}
                    timezone={timezone}
                    language={language}
                  />
                )}

                {step === 'customer' && (
                  <div className="booking-form">
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
                    <dl className="review-list">
                      <div>
                        <dt>{t('booking.customer.name')}</dt>
                        <dd>{customer.customer_name.trim()}</dd>
                      </div>
                      <div>
                        <dt>{t('booking.customer.email')}</dt>
                        <dd>{customer.customer_email.trim()}</dd>
                      </div>
                      <div>
                        <dt>{t('booking.customer.phone')}</dt>
                        <dd>{customer.customer_phone.trim()}</dd>
                      </div>
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
                      <ArrowRightIcon size={18} />
                    </button>
                  )}
                </div>
              </section>

              <Summary service={service} professional={professional} startsAt={startsAt} timezone={timezone} language={language} />
            </div>
          </div>
        )}
      </main>
    </div>
  )
}

function OptionList({ items, failed, onRetry, selectedId, onSelect, labels, renderItem, icon }) {
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
  if (items === null) return <p role="status" className="booking-loading">{t(labels.loading)}</p>
  if (items.length === 0) return <p className="booking-hint">{t(labels.empty)}</p>

  return (
    <ul className="option-list">
      {items.map((item) => (
        <li key={item.id}>
          <button type="button" className="option-card" aria-pressed={item.id === selectedId} onClick={() => onSelect(item.id)}>
            <span className="icon-bubble option-icon">{icon}</span>
            <span className="option-body">{renderItem(item)}</span>
            <span className="option-check" aria-hidden="true">
              <CheckIcon size={16} />
            </span>
          </button>
        </li>
      ))}
    </ul>
  )
}

/**
 * Date carousel and time grid, as in the reference: one row of date cards
 * (weekday / day + month) with previous/next arrows, then the times of the
 * chosen day. Every date of the horizon stays in the row (it scrolls), so
 * keyboard users can still Tab through all of them.
 */
function DateTimeStep({ dates, date, onDate, emptyDays, slots, failed, onRetry, startsAt, onSlot, timezone, language }) {
  const { t } = useTranslation()
  const rowRef = useRef(null)

  // Keep the selected date card in view (e.g. after coming back to this step).
  useEffect(() => {
    rowRef.current?.querySelector('[aria-pressed="true"]')?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
  }, [date])

  function scrollDates(direction) {
    const row = rowRef.current
    if (row) row.scrollBy({ left: direction * row.clientWidth * 0.8, behavior: 'smooth' })
  }

  return (
    <div className="booking-datetime">
      <h2 className="sr-only" id="booking-dates-label">
        {t('booking.datetime.datesLabel')}
      </h2>
      <div className="date-carousel">
        <button type="button" className="date-arrow" onClick={() => scrollDates(-1)} aria-label={t('booking.datetime.previousDays')}>
          <ChevronLeftIcon size={20} />
        </button>
        <ul className="date-list" ref={rowRef} aria-labelledby="booking-dates-label">
          {dates.map((value) => {
            const card = formatDateCard(value, language)
            const knownEmpty = emptyDays.has(value)
            return (
              <li key={value}>
                <button
                  type="button"
                  className="date-card"
                  aria-pressed={value === date}
                  aria-label={`${formatLongDate(value, language)}${knownEmpty ? ` — ${t('booking.datetime.noSlots')}` : ''}`}
                  data-empty={knownEmpty || undefined}
                  onClick={() => onDate(value)}
                >
                  <span className="date-card-weekday">{card.weekday}</span>
                  <span className="date-card-day">{card.dayMonth}</span>
                </button>
              </li>
            )
          })}
        </ul>
        <button type="button" className="date-arrow" onClick={() => scrollDates(1)} aria-label={t('booking.datetime.nextDays')}>
          <ChevronRightIcon size={20} />
        </button>
      </div>

      {date === null && <p className="booking-hint">{t('booking.datetime.pickDate')}</p>}

      {date !== null && (
        <>
          <h2 className="booking-subheading" id="booking-slots-label">
            {t('booking.datetime.slotsHeading')}
            <span className="sr-only">: {formatLongDate(date, language)}</span>
          </h2>

          {failed && (
            <div role="alert" className="public-notice public-notice--error">
              <p>{t('booking.datetime.error')}</p>
              <button type="button" className="secondary-button" onClick={onRetry}>
                {t('booking.actions.retry')}
              </button>
            </div>
          )}
          {!failed && slots === null && <p role="status" className="booking-loading">{t('booking.datetime.loading')}</p>}
          {!failed && slots !== null && slots.length === 0 && (
            <div className="empty-state" role="status">
              <span className="icon-bubble icon-bubble--danger empty-state-icon">
                <CalendarIcon size={22} />
              </span>
              <span>
                <strong className="empty-state-title">{t('booking.datetime.emptyTitle')}</strong>
                <span className="empty-state-hint">{t('booking.datetime.emptyHint')}</span>
              </span>
            </div>
          )}
          {!failed && slots !== null && slots.length > 0 && (
            <ul className="slot-list" aria-labelledby="booking-slots-label">
              {slots.map((slot) => (
                <li key={slot.starts_at}>
                  <button type="button" className="slot-button" aria-pressed={slot.starts_at === startsAt} onClick={() => onSlot(slot.starts_at)}>
                    {formatTime(slot.starts_at, timezone)}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {timezone && <p className="booking-hint booking-timezone">{t('booking.datetime.timezoneNote', { timezone })}</p>}
        </>
      )}
    </div>
  )
}

function Field({ id, label, hint, error, children }) {
  return (
    <div className="booking-field">
      <label htmlFor={id}>{label}</label>
      {children}
      {hint && (
        <span className="booking-hint" id={`${id}-hint`}>
          {hint}
        </span>
      )}
      {error && (
        <span className="field-error" id={`${id}-error`}>
          {error[0]}
        </span>
      )}
    </div>
  )
}

/**
 * "Seu agendamento" card: service, professional and date/time grouped with
 * the reference's icons. Each value keeps its own <dt>, visually hidden,
 * for assistive technology.
 */
function Summary({ service, professional, startsAt, timezone, language }) {
  const { t } = useTranslation()
  const toChoose = <span className="summary-pending">{t('booking.summary.toChoose')}</span>
  const localDate = startsAt && timezone ? zonedDate(startsAt, timezone) : null

  return (
    <aside className="booking-summary" aria-labelledby="booking-summary-title">
      <h2 id="booking-summary-title" className="booking-summary-title">
        {t('booking.summary.title')}
      </h2>
      <dl className="summary-groups">
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <ScissorsIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.service')}</dt>
            <dd className="summary-main">{service ? service.name : toChoose}</dd>
            <dt className="sr-only">{t('booking.summary.duration')}</dt>
            <dd className="summary-sub summary-inline">{service ? t('booking.durationMinutes', { count: service.duration_minutes }) : ''}</dd>
            <dt className="sr-only">{t('booking.summary.price')}</dt>
            <dd className="summary-sub summary-inline">{service ? formatPrice(service.price, language) : ''}</dd>
          </div>
        </div>
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <UserIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.professional')}</dt>
            <dd className="summary-main">{professional ? professional.name : toChoose}</dd>
            {professional && <dd className="summary-sub" aria-hidden="true">{t('booking.summary.professionalRole')}</dd>}
          </div>
        </div>
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <CalendarIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.date')}</dt>
            <dd className="summary-main summary-inline">{localDate ? formatDayMonth(localDate, language) : toChoose}</dd>
            <dt className="sr-only">{t('booking.summary.time')}</dt>
            <dd className="summary-main summary-inline">{startsAt && timezone ? formatTime(startsAt, timezone) : toChoose}</dd>
            {localDate && <dd className="summary-sub" aria-hidden="true">{formatWeekday(localDate, language)}</dd>}
          </div>
        </div>
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
  const localDate = zonedDate(result.starts_at, zone)
  const emailKey = {
    sent: 'booking.done.emailSent',
    skipped: 'booking.done.emailSkipped',
  }[result.notification_status] ?? 'booking.done.emailNotSent'

  return (
    <section className="booking-done theme-card" aria-labelledby="booking-done-heading">
      <span className="icon-bubble icon-bubble--large icon-bubble--success done-icon" aria-hidden="true">
        <CheckIcon size={30} />
      </span>
      <h1 id="booking-done-heading" ref={headingRef} tabIndex={-1} className="booking-heading">
        {t('booking.done.heading')}
      </h1>
      <p role="status" className="booking-intro">
        {t('booking.done.intro')}
      </p>

      <dl className="summary-groups done-summary">
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <ScissorsIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.service')}</dt>
            <dd className="summary-main">{result.service.name}</dd>
            <dt className="sr-only">{t('booking.summary.duration')}</dt>
            <dd className="summary-sub summary-inline">{t('booking.durationMinutes', { count: result.service.duration_minutes })}</dd>
            <dt className="sr-only">{t('booking.summary.price')}</dt>
            <dd className="summary-sub summary-inline">{formatPrice(result.service.price, i18n.language)}</dd>
          </div>
        </div>
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <UserIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.professional')}</dt>
            <dd className="summary-main">{result.professional.name}</dd>
          </div>
        </div>
        <div className="summary-group">
          <span className="icon-bubble summary-icon">
            <CalendarIcon size={20} />
          </span>
          <div className="summary-lines">
            <dt className="sr-only">{t('booking.summary.date')}</dt>
            <dd className="summary-main">{formatLongDate(localDate, i18n.language)}</dd>
            <dt className="sr-only">{t('booking.summary.time')}</dt>
            <dd className="summary-sub">
              {formatTime(result.starts_at, zone)}–{formatTime(result.ends_at, zone)}
            </dd>
          </div>
        </div>
      </dl>

      <p className="done-reference">
        {t('booking.done.reference')}: <span className="summary-reference">{result.public_id}</span>
      </p>

      <div className={`public-notice done-email ${result.notification_status === 'sent' ? '' : 'public-notice--info'}`}>
        <MailIcon size={20} />
        <p>{t(emailKey, { email: customerEmail })}</p>
      </div>

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
