import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { getAgenda } from '../../api/agenda'
import { getBusinessSettings } from '../../api/businessSettings'
import { listProfessionals } from '../../api/professionals'
import { listServices } from '../../api/services'
import { AdminShell } from '../../components/AdminShell'
import { BoltIcon, CalendarIcon, CloseIcon, ChevronLeftIcon, ChevronRightIcon, PlusIcon } from '../../components/PublicIcons'
import { useMediaQuery } from '../../hooks/useMediaQuery'
import { formatLongDate, formatTime, todayInZone, zonedDate } from '../../utils/publicFormat'
import { addDays } from '../../utils/timezone'
import { AgendaGrid } from './agenda/AgendaGrid'
import { AgendaList } from './agenda/AgendaList'
import { AppointmentDetails } from './agenda/AppointmentDetails'
import { NewAppointment } from './agenda/NewAppointment'
import { isSessionError } from './agenda/agendaText'
import './agenda/agenda.css'

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const STATUSES = ['confirmed', 'completed', 'no_show', 'cancelled']

/**
 * /admin/agenda — the daily operation (docs/planejamento-barbearia-mvp.md,
 * seção 3; visual reference docs/design/1.png). Date and filters live in
 * the URL (`data`, `profissional`, `servico`, `status`), so a refresh, an
 * action or a language switch keeps them. Everything shown comes from the
 * API: positions, allowed actions and notification state included.
 */
function Agenda() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const wide = useMediaQuery('(min-width: 960px)')
  const filterIds = { professional: useId(), service: useId(), status: useId() }

  const [timeZone, setTimeZone] = useState(null)
  const [settingsFailed, setSettingsFailed] = useState(false)
  const [catalog, setCatalog] = useState({ services: [], professionals: [] })
  const [answer, setAnswer] = useState(null) // { base, key, data } | { base, key, error }
  const [reloadToken, setReloadToken] = useState(0)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [detailsId, setDetailsId] = useState(null)
  const [newMode, setNewMode] = useState(null) // 'scheduled' | 'now'
  const [announcement, setAnnouncement] = useState('')
  const [flash, setFlash] = useState(null)
  const request = useRef(0)

  const requestedDate = params.get('data')
  const date = requestedDate && DATE_PATTERN.test(requestedDate) ? requestedDate : timeZone ? todayInZone(timeZone) : null
  const professionalId = params.get('profissional') ?? ''
  const serviceFilter = params.get('servico') ?? ''
  const statusFilter = params.get('status') ?? ''
  const activeFilters = [professionalId, serviceFilter, statusFilter].filter(Boolean).length
  const [filtersOpen, setFiltersOpen] = useState(activeFilters > 0)

  const setParam = useCallback(
    (name, value) => {
      setParams(
        (current) => {
          const next = new URLSearchParams(current)
          if (value) next.set(name, value)
          else next.delete(name)
          return next
        },
        { replace: true }
      )
    },
    [setParams]
  )

  const markSessionExpired = useCallback(() => setSessionExpired(true), [])

  // --- Loading ---------------------------------------------------------------

  const fetchSettings = useCallback(() => {
    getBusinessSettings()
      .then((body) => setTimeZone(body.data.timezone))
      .catch((error) => {
        if (isSessionError(error)) setSessionExpired(true)
        setSettingsFailed(true)
      })
  }, [])

  useEffect(() => {
    fetchSettings()
    // Services and professionals for the filters and the booking form; a
    // failure here only empties those lists.
    Promise.all([listServices(), listProfessionals()])
      .then(([services, professionals]) => setCatalog({ services: services.data, professionals: professionals.data }))
      .catch(() => {})
  }, [fetchSettings])

  const base = date ? `${date}|${professionalId}` : null
  const key = base ? `${base}|${reloadToken}` : null

  const fetchAgenda = useCallback((forBase, forKey) => {
    const current = ++request.current
    const [day, professional] = forBase.split('|')
    getAgenda({ date: day, professionalId: professional })
      .then((body) => {
        if (current === request.current) setAnswer({ base: forBase, key: forKey, data: body.data })
      })
      .catch((error) => {
        if (current !== request.current) return
        if (isSessionError(error)) setSessionExpired(true)
        setAnswer({ base: forBase, key: forKey, error })
      })
  }, [])

  useEffect(() => {
    if (base) fetchAgenda(base, key)
  }, [base, key, fetchAgenda])

  // While refreshing the same day and filter, keep showing what is there.
  const sameView = answer !== null && answer.base === base
  const agenda = sameView ? answer.data ?? null : null
  const failed = sameView && answer.key === key && answer.error ? answer.error : null
  const updating = sameView && answer.key !== key

  const refresh = useCallback(() => setReloadToken((token) => token + 1), [])

  // --- Navigation -------------------------------------------------------------

  function goToDate(next) {
    setFlash(null)
    setParam('data', next)
    setAnnouncement(t('agenda.dayLabel', { date: formatLongDate(next, i18n.language) }))
  }

  // --- Rendering ----------------------------------------------------------------

  const appointments = (agenda?.appointments ?? []).filter(
    (appointment) =>
      (!serviceFilter || String(appointment.service.id) === serviceFilter) && (!statusFilter || appointment.status === statusFilter)
  )
  const filtered = Boolean(serviceFilter || statusFilter)
  const isToday = date && timeZone ? date === todayInZone(timeZone) : false

  function onCreated(created) {
    const status = created.notification_status
    const email =
      status === 'sent'
        ? t('agenda.form.emailSent')
        : status === 'failed' || status === 'pending'
          ? t('agenda.form.emailFailed')
          : t('agenda.form.emailNone')
    const message = t(newMode === 'now' ? 'agenda.form.createdNow' : 'agenda.form.createdScheduled', {
      customer: created.customer.name,
      time: formatTime(created.starts_at, created.timezone),
    })

    setNewMode(null)
    setFlash(`${message} ${email}`)
    const createdDate = zonedDate(created.starts_at, created.timezone)
    if (createdDate !== date) setParam('data', createdDate)
    refresh()
  }

  const refineFilters = (
    <>
      <div className="agenda-filter">
        <label htmlFor={filterIds.professional}>{t('agenda.filters.professional')}</label>
        <select id={filterIds.professional} value={professionalId} onChange={(event) => setParam('profissional', event.target.value)}>
          <option value="">{t('agenda.filters.allProfessionals')}</option>
          {catalog.professionals.map((professional) => (
            <option key={professional.id} value={professional.id}>
              {professional.name}
              {!professional.is_active ? ` (${t('agenda.inactive')})` : ''}
            </option>
          ))}
        </select>
      </div>
      <div className="agenda-filter">
        <label htmlFor={filterIds.service}>{t('agenda.filters.service')}</label>
        <select id={filterIds.service} value={serviceFilter} onChange={(event) => setParam('servico', event.target.value)}>
          <option value="">{t('agenda.filters.allServices')}</option>
          {catalog.services.map((service) => (
            <option key={service.id} value={service.id}>
              {service.name}
            </option>
          ))}
        </select>
      </div>
      <div className="agenda-filter">
        <label htmlFor={filterIds.status}>{t('agenda.filters.status')}</label>
        <select id={filterIds.status} value={statusFilter} onChange={(event) => setParam('status', event.target.value)}>
          <option value="">{t('agenda.filters.allStatuses')}</option>
          {STATUSES.map((status) => (
            <option key={status} value={status}>
              {t(`agenda.status.${status}`)}
            </option>
          ))}
        </select>
      </div>
    </>
  )

  return (
    <AdminShell>
      <div className="agenda-page">
        <p className="sr-only" role="status" aria-live="polite">
          {announcement}
        </p>

        {sessionExpired && (
          <div role="alert" className="agenda-notice agenda-notice--error agenda-session">
            <p>{t('agenda.sessionExpired')}</p>
            <Link className="primary-button" to="/admin/login">
              {t('agenda.signInAgain')}
            </Link>
          </div>
        )}

        <header className="agenda-header">
          <div>
            <h1 className="agenda-title">
              {t('agenda.title')}
            </h1>
            {date && <p className="agenda-date">{formatLongDate(date, i18n.language)}</p>}
          </div>

          <div className="agenda-toolbar">
            <div className="agenda-day-nav" role="group" aria-label={t('agenda.dateLabel')}>
              <button type="button" className="icon-button" onClick={() => goToDate(addDays(date, -1))} disabled={!date} aria-label={t('agenda.previousDay')}>
                <ChevronLeftIcon size={20} />
              </button>
              <button
                type="button"
                className="secondary-button agenda-today"
                onClick={() => goToDate(todayInZone(timeZone))}
                disabled={!timeZone || isToday}
              >
                {t('agenda.today')}
              </button>
              <button type="button" className="icon-button" onClick={() => goToDate(addDays(date, 1))} disabled={!date} aria-label={t('agenda.nextDay')}>
                <ChevronRightIcon size={20} />
              </button>
            </div>

            <div className="agenda-actions">
              {/* On narrow screens the same action is the fixed button at the bottom (1.png). */}
              {wide && (
                <button type="button" className="primary-button" onClick={() => setNewMode('scheduled')} disabled={!timeZone}>
                  <PlusIcon size={18} />
                  {t('agenda.actions.new')}
                </button>
              )}
              <button type="button" className="secondary-button agenda-now-button" onClick={() => setNewMode('now')} disabled={!timeZone}>
                <BoltIcon size={18} />
                {t('agenda.actions.now')}
              </button>
            </div>
          </div>
        </header>

        <section className="agenda-filters theme-card" aria-label={t('agenda.filtersLabel')}>
          <div className="agenda-filter">
            <label htmlFor="agenda-date-input">{t('agenda.dateLabel')}</label>
            <span className="agenda-filter-control">
              <CalendarIcon size={18} />
              <input
                id="agenda-date-input"
                type="date"
                value={date ?? ''}
                onChange={(event) => event.target.value && goToDate(event.target.value)}
                disabled={!date}
              />
            </span>
          </div>
          {wide ? (
            refineFilters
          ) : (
            // On a phone the date stays in view and the rest folds away, so
            // the day's bookings start on the first screen (opened when a
            // filter is already active, and the summary says how many).
            <details className="agenda-more-filters" open={filtersOpen} onToggle={(event) => setFiltersOpen(event.currentTarget.open)}>
              <summary>{activeFilters ? t('agenda.filters.moreActive', { count: activeFilters }) : t('agenda.filters.more')}</summary>
              <div className="agenda-more-filters-body">{refineFilters}</div>
            </details>
          )}
        </section>

        {flash && (
          <div className="agenda-notice agenda-notice--success agenda-flash" role="status">
            <p>{flash}</p>
            <button type="button" className="icon-button" onClick={() => setFlash(null)} aria-label={t('agenda.details.close')}>
              <CloseIcon size={18} />
            </button>
          </div>
        )}

        {settingsFailed && !sessionExpired && (
          <div role="alert" className="agenda-notice agenda-notice--error">
            <p>{t('agenda.error')}</p>
            <button
              type="button"
              className="secondary-button"
              onClick={() => {
                setSettingsFailed(false)
                fetchSettings()
              }}
            >
              {t('agenda.retry')}
            </button>
          </div>
        )}

        {base && !agenda && !failed && (
          <p role="status" className="agenda-muted agenda-loading">
            {t('agenda.loading')}
          </p>
        )}

        {failed && !sessionExpired && (
          <div role="alert" className="agenda-notice agenda-notice--error">
            <p>{t('agenda.error')}</p>
            <button type="button" className="secondary-button" onClick={refresh}>
              {t('agenda.retry')}
            </button>
          </div>
        )}

        {agenda && (
          <section className="agenda-day" aria-label={t('agenda.dayLabel', { date: formatLongDate(agenda.date, i18n.language) })} aria-busy={updating}>
            {updating && (
              <p role="status" className="agenda-muted agenda-updating">
                {t('agenda.updating')}
              </p>
            )}

            {agenda.professionals.length === 0 ? (
              <div className="agenda-empty theme-card">
                <p>{t('agenda.noProfessionals')}</p>
              </div>
            ) : agenda.hours === null ? (
              <div className="agenda-empty theme-card">
                <p className="agenda-empty-title">{t('agenda.empty')}</p>
                <p className="agenda-muted">{t('agenda.noHours')}</p>
              </div>
            ) : (
              <>
                {appointments.length === 0 && (
                  <div className="agenda-empty agenda-empty--inline">
                    <p className="agenda-empty-title">{filtered ? t('agenda.noMatches') : t('agenda.empty')}</p>
                    {!filtered && <p className="agenda-muted">{t('agenda.emptyHint')}</p>}
                  </div>
                )}
                {wide ? (
                  <AgendaGrid agenda={agenda} appointments={appointments} onOpen={setDetailsId} />
                ) : (
                  <AgendaList agenda={agenda} appointments={appointments} onOpen={setDetailsId} />
                )}
              </>
            )}
          </section>
        )}

        {!wide && timeZone && (
          <button type="button" className="primary-button agenda-fab" onClick={() => setNewMode('scheduled')}>
            <PlusIcon size={20} />
            {t('agenda.actions.new')}
          </button>
        )}
      </div>

      {detailsId && (
        <AppointmentDetails
          publicId={detailsId}
          onClose={() => setDetailsId(null)}
          onChanged={refresh}
          onSessionExpired={markSessionExpired}
        />
      )}

      {newMode && timeZone && (
        <NewAppointment
          mode={newMode}
          date={date}
          timeZone={timeZone}
          services={catalog.services}
          professionals={catalog.professionals}
          defaultProfessionalId={professionalId}
          onClose={() => setNewMode(null)}
          onCreated={onCreated}
          onSessionExpired={markSessionExpired}
        />
      )}
    </AdminShell>
  )
}

export default Agenda
