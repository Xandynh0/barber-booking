import { useCallback, useEffect, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AdminLayout } from '../../components/AdminLayout'
import { ApiError } from '../../api/client'
import { listProfessionals } from '../../api/professionals'
import { getWorkingHours, updateWorkingHours } from '../../api/workingHours'
import './admin.css'

function emptyWeek() {
  return Array.from({ length: 7 }, (_, weekday) => ({ weekday, periods: [] }))
}

function errorMessageFor(t, error) {
  if (error.code === 'NETWORK_ERROR') {
    return t('workingHours.errors.network')
  }
  if (error.code === 'NOT_FOUND') {
    return t('workingHours.errors.notFound')
  }

  return error.message || t('workingHours.errors.generic')
}

/**
 * Field errors come back as nested paths like "days.1.periods.0.end_time".
 * Grouped by day index so each day's section can show what's wrong with it,
 * without trying to re-target the exact input (the payload doesn't carry a
 * stable per-period id to match against).
 */
function groupFieldErrorsByDay(fields) {
  const byDay = {}

  for (const [key, messages] of Object.entries(fields ?? {})) {
    const match = key.match(/^days\.(\d+)\./)
    if (!match) continue

    const dayIndex = Number(match[1])
    byDay[dayIndex] = [...(byDay[dayIndex] ?? []), ...messages]
  }

  return byDay
}

function WorkingHours() {
  const { t } = useTranslation()
  const weekdayLabels = t('workingHours.weekdays', { returnObjects: true })
  const [professionals, setProfessionals] = useState(null)
  const [professionalId, setProfessionalId] = useState('')
  const [days, setDays] = useState(emptyWeek())
  // Starts true: the form must stay hidden until the first schedule load
  // for the initially-selected professional resolves, otherwise it briefly
  // renders with the default empty week before the fetch completes.
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const [fieldErrorsByDay, setFieldErrorsByDay] = useState({})
  const [formError, setFormError] = useState(null)
  const [successMessage, setSuccessMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const professionalSelectId = useId()

  const loadProfessionals = useCallback(() => {
    setLoadError(null)

    listProfessionals()
      .then((body) => {
        setProfessionals(body.data)
        if (body.data.length > 0) {
          setProfessionalId(String(body.data[0].id))
        }
      })
      .catch((error) => {
        setLoadError(error)
      })
  }, [])

  useEffect(() => {
    loadProfessionals()
  }, [loadProfessionals])

  const loadSchedule = useCallback(
    (id) => {
      if (!id) return

      setLoading(true)
      setLoadError(null)
      setFormError(null)
      setFieldErrorsByDay({})
      setSuccessMessage(null)

      getWorkingHours(id)
        .then((body) => {
          setDays(body.data.days)
        })
        .catch((error) => {
          setLoadError(error)
        })
        .finally(() => setLoading(false))
    },
    []
  )

  useEffect(() => {
    if (professionalId) {
      loadSchedule(professionalId)
    }
  }, [professionalId, loadSchedule])

  function addPeriod(dayIndex) {
    setDays((prev) =>
      prev.map((day, index) =>
        index === dayIndex ? { ...day, periods: [...day.periods, { start_time: '', end_time: '' }] } : day
      )
    )
  }

  function removePeriod(dayIndex, periodIndex) {
    setDays((prev) =>
      prev.map((day, index) =>
        index === dayIndex ? { ...day, periods: day.periods.filter((_, i) => i !== periodIndex) } : day
      )
    )
  }

  function updatePeriod(dayIndex, periodIndex, field, value) {
    setDays((prev) =>
      prev.map((day, index) =>
        index === dayIndex
          ? {
              ...day,
              periods: day.periods.map((period, i) => (i === periodIndex ? { ...period, [field]: value } : period)),
            }
          : day
      )
    )
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting || !professionalId) return

    setSubmitting(true)
    setFieldErrorsByDay({})
    setFormError(null)
    setSuccessMessage(null)

    try {
      const body = await updateWorkingHours(professionalId, { days })
      setDays(body.data.days)
      setSuccessMessage(t('workingHours.savedSuccess'))
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        setFieldErrorsByDay(groupFieldErrorsByDay(error.fields))
      } else if (error instanceof ApiError) {
        setFormError(errorMessageFor(t, error))
      } else {
        setFormError(t('workingHours.errors.generic'))
      }
      // Form values are intentionally left untouched on failure.
    } finally {
      setSubmitting(false)
    }
  }

  const loadErrorMessage =
    loadError && (loadError instanceof ApiError ? errorMessageFor(t, loadError) : t('workingHours.errors.network'))

  return (
    <AdminLayout>
      <div className="admin-content">
        <section className="admin-card">
          <h2>{t('workingHours.title')}</h2>

          {loadError && professionals === null && (
            <div role="alert">
              <p className="admin-form-error">{loadErrorMessage}</p>
              <button type="button" className="admin-button" onClick={loadProfessionals}>
                {t('common.actions.tryAgain')}
              </button>
            </div>
          )}

          {professionals !== null && professionals.length === 0 && <p>{t('workingHours.noProfessionalsYet')}</p>}

          {professionals !== null && professionals.length > 0 && (
            <>
              <div className="admin-field">
                <label htmlFor={professionalSelectId}>{t('workingHours.professionalLabel')}</label>
                <select
                  id={professionalSelectId}
                  value={professionalId}
                  onChange={(event) => setProfessionalId(event.target.value)}
                >
                  {professionals.map((professional) => (
                    <option key={professional.id} value={professional.id}>
                      {professional.name}
                      {!professional.is_active ? ` ${t('professionals.inactiveTag')}` : ''}
                    </option>
                  ))}
                </select>
              </div>

              {formError && (
                <p className="admin-form-error" role="alert">
                  {formError}
                </p>
              )}
              {successMessage && (
                <p className="admin-success" role="status">
                  {successMessage}
                </p>
              )}

              {loading && <p>{t('common.actions.loading')}</p>}

              {loadError && professionals !== null && (
                <div role="alert">
                  <p className="admin-form-error">{loadErrorMessage}</p>
                  <button type="button" className="admin-button" onClick={() => loadSchedule(professionalId)}>
                    {t('common.actions.tryAgain')}
                  </button>
                </div>
              )}

              {!loading && !loadError && (
                <form onSubmit={handleSubmit} noValidate>
                  {days.map((day, dayIndex) => (
                    <div className="admin-day-row" key={day.weekday}>
                      <h3>{weekdayLabels[day.weekday]}</h3>

                      {day.periods.length === 0 && <p className="admin-day-off">{t('workingHours.dayOff')}</p>}

                      {day.periods.map((period, periodIndex) => (
                        <div className="admin-period-row" key={periodIndex}>
                          <div className="admin-field">
                            <label htmlFor={`start-${dayIndex}-${periodIndex}`}>{t('workingHours.startLabel')}</label>
                            <input
                              id={`start-${dayIndex}-${periodIndex}`}
                              type="time"
                              value={period.start_time}
                              onChange={(event) =>
                                updatePeriod(dayIndex, periodIndex, 'start_time', event.target.value)
                              }
                              required
                            />
                          </div>
                          <div className="admin-field">
                            <label htmlFor={`end-${dayIndex}-${periodIndex}`}>{t('workingHours.endLabel')}</label>
                            <input
                              id={`end-${dayIndex}-${periodIndex}`}
                              type="time"
                              value={period.end_time}
                              onChange={(event) => updatePeriod(dayIndex, periodIndex, 'end_time', event.target.value)}
                              required
                            />
                          </div>
                          <button
                            type="button"
                            className="admin-button admin-button--secondary"
                            onClick={() => removePeriod(dayIndex, periodIndex)}
                          >
                            {t('workingHours.removePeriod')}
                          </button>
                        </div>
                      ))}

                      {fieldErrorsByDay[dayIndex]?.map((message, i) => (
                        <p className="admin-field-error" key={i}>
                          {message}
                        </p>
                      ))}

                      <button
                        type="button"
                        className="admin-button admin-button--secondary"
                        onClick={() => addPeriod(dayIndex)}
                      >
                        {t('workingHours.addPeriod')}
                      </button>
                    </div>
                  ))}

                  <div className="admin-form-actions">
                    <button type="submit" className="admin-button" disabled={submitting}>
                      {submitting ? t('common.actions.saving') : t('workingHours.saveButton')}
                    </button>
                  </div>
                </form>
              )}
            </>
          )}
        </section>
      </div>
    </AdminLayout>
  )
}

export default WorkingHours
