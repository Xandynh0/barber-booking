import { useCallback, useEffect, useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AdminLayout } from '../../components/AdminLayout'
import { ApiError } from '../../api/client'
import { getBusinessSettings } from '../../api/businessSettings'
import { listProfessionals } from '../../api/professionals'
import {
  createScheduleBlock,
  deleteScheduleBlock,
  deleteScheduleBlockGroup,
  listScheduleBlocks,
} from '../../api/scheduleBlocks'
import { addDays, utcIsoToZonedParts, zonedWallTimeToUtcIso } from '../../utils/timezone'
import './admin.css'

const emptyForm = {
  scope: 'professional',
  professional_id: '',
  wholeDay: false,
  wholeDayDate: '',
  starts_date: '',
  starts_time: '',
  ends_date: '',
  ends_time: '',
  reason: '',
}

function errorMessageFor(t, error) {
  if (error.code === 'NETWORK_ERROR') {
    return t('scheduleBlocks.errors.network')
  }
  if (error.code === 'NOT_FOUND') {
    return t('scheduleBlocks.errors.notFound')
  }

  return error.message || t('scheduleBlocks.errors.generic')
}

function ScheduleBlocks() {
  const { t, i18n } = useTranslation()
  const [timezone, setTimezone] = useState(null)
  const [professionals, setProfessionals] = useState(null)
  const [blocks, setBlocks] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [successMessage, setSuccessMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [removingKey, setRemovingKey] = useState(null)

  const scopeProfessionalId = useId()
  const scopeShopId = useId()
  const professionalSelectId = useId()
  const wholeDayId = useId()
  const wholeDayDateId = useId()
  const startsDateId = useId()
  const startsTimeId = useId()
  const endsDateId = useId()
  const endsTimeId = useId()
  const reasonId = useId()

  const loadAll = useCallback(() => {
    setLoadError(null)
    setBlocks(null)

    Promise.all([listScheduleBlocks(), listProfessionals(), getBusinessSettings()])
      .then(([blocksBody, professionalsBody, settingsBody]) => {
        setBlocks(blocksBody.data)
        setProfessionals(professionalsBody.data)
        setTimezone(settingsBody.data.timezone)
        setForm((prev) => ({
          ...prev,
          professional_id: prev.professional_id || String(professionalsBody.data[0]?.id ?? ''),
        }))
      })
      .catch((error) => {
        setLoadError(error instanceof ApiError ? errorMessageFor(t, error) : t('scheduleBlocks.errors.network'))
      })
  }, [t])

  useEffect(() => {
    loadAll()
  }, [loadAll])

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)

    try {
      let startsAt
      let endsAt
      if (form.scope === 'shop' && form.wholeDay) {
        startsAt = zonedWallTimeToUtcIso(form.wholeDayDate, '00:00', timezone)
        endsAt = zonedWallTimeToUtcIso(addDays(form.wholeDayDate, 1), '00:00', timezone)
      } else {
        startsAt = zonedWallTimeToUtcIso(form.starts_date, form.starts_time, timezone)
        endsAt = zonedWallTimeToUtcIso(form.ends_date, form.ends_time, timezone)
      }

      const payload = {
        scope: form.scope,
        starts_at: startsAt,
        ends_at: endsAt,
        reason: form.reason === '' ? null : form.reason,
      }
      if (form.scope === 'professional') {
        payload.professional_id = Number(form.professional_id)
      }

      await createScheduleBlock(payload)
      setSuccessMessage(
        form.scope === 'shop' ? t('scheduleBlocks.closureCreatedSuccess') : t('scheduleBlocks.blockCreatedSuccess')
      )
      setForm((prev) => ({ ...emptyForm, scope: prev.scope, professional_id: prev.professional_id }))
      loadAll()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        setFieldErrors(error.fields ?? {})
      } else if (error instanceof ApiError) {
        setFormError(errorMessageFor(t, error))
      } else {
        setFormError(t('scheduleBlocks.errors.generic'))
      }
      // Form values are intentionally left untouched on failure.
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDeleteIndividual(item) {
    const confirmed = window.confirm(
      t('scheduleBlocks.confirmRemoveBlock', { name: item.professional.name, range: formatRange(item, timezone, i18n.language) })
    )
    if (!confirmed) return

    setRemovingKey(`professional-${item.id}`)
    setFormError(null)

    try {
      await deleteScheduleBlock(item.id)
      setBlocks((prev) => prev.filter((b) => !(b.kind === 'professional' && b.id === item.id)))
    } catch (error) {
      setFormError(error instanceof ApiError ? errorMessageFor(t, error) : t('scheduleBlocks.errors.removeGeneric'))
    } finally {
      setRemovingKey(null)
    }
  }

  async function handleDeleteGroup(item) {
    const names = item.professionals.map((p) => p.name).join(', ')
    const confirmed = window.confirm(
      t('scheduleBlocks.confirmRemoveClosure', {
        range: formatRange(item, timezone, i18n.language),
        count: item.professionals.length,
        names,
      })
    )
    if (!confirmed) return

    setRemovingKey(`shop-${item.group_id}`)
    setFormError(null)

    try {
      await deleteScheduleBlockGroup(item.group_id)
      setBlocks((prev) => prev.filter((b) => !(b.kind === 'shop' && b.group_id === item.group_id)))
    } catch (error) {
      setFormError(error instanceof ApiError ? errorMessageFor(t, error) : t('scheduleBlocks.errors.removeGeneric'))
    } finally {
      setRemovingKey(null)
    }
  }

  const loading = blocks === null && !loadError

  return (
    <AdminLayout>
      <div className="admin-content">
        <section className="admin-card">
          <h2>{t('scheduleBlocks.newTitle')}</h2>

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

          {professionals !== null && professionals.length === 0 && <p>{t('scheduleBlocks.noProfessionalsYet')}</p>}

          {professionals !== null && professionals.length > 0 && (
            <form onSubmit={handleSubmit} noValidate>
              <div className="admin-form-grid">
                <fieldset className="admin-field admin-field--full">
                  <legend>{t('scheduleBlocks.scopeLegend')}</legend>
                  <div className="admin-radio-group">
                    <label className="admin-radio-option" htmlFor={scopeProfessionalId}>
                      <input
                        id={scopeProfessionalId}
                        type="radio"
                        name="scope"
                        value="professional"
                        checked={form.scope === 'professional'}
                        onChange={() => setForm((prev) => ({ ...prev, scope: 'professional' }))}
                      />
                      {t('scheduleBlocks.scopeProfessional')}
                    </label>
                    <label className="admin-radio-option" htmlFor={scopeShopId}>
                      <input
                        id={scopeShopId}
                        type="radio"
                        name="scope"
                        value="shop"
                        checked={form.scope === 'shop'}
                        onChange={() => setForm((prev) => ({ ...prev, scope: 'shop' }))}
                      />
                      {t('scheduleBlocks.scopeShop')}
                    </label>
                  </div>
                  {form.scope === 'shop' && <p className="admin-hint">{t('scheduleBlocks.shopExplanation')}</p>}
                </fieldset>

                {form.scope === 'professional' && (
                  <div className="admin-field admin-field--full">
                    <label htmlFor={professionalSelectId}>{t('scheduleBlocks.professionalLabel')}</label>
                    <select
                      id={professionalSelectId}
                      value={form.professional_id}
                      onChange={(event) => setForm((prev) => ({ ...prev, professional_id: event.target.value }))}
                    >
                      {professionals.map((professional) => (
                        <option key={professional.id} value={professional.id}>
                          {professional.name}
                        </option>
                      ))}
                    </select>
                    {fieldErrors.professional_id && (
                      <span className="admin-field-error">{fieldErrors.professional_id[0]}</span>
                    )}
                  </div>
                )}

                {form.scope === 'shop' && (
                  <div className="admin-field admin-field-checkbox admin-field--full">
                    <input
                      id={wholeDayId}
                      type="checkbox"
                      checked={form.wholeDay}
                      onChange={(event) => setForm((prev) => ({ ...prev, wholeDay: event.target.checked }))}
                    />
                    <label htmlFor={wholeDayId}>{t('scheduleBlocks.wholeDay')}</label>
                  </div>
                )}

                <p className="admin-hint admin-field--full">{t('scheduleBlocks.timezoneHint', { timezone })}</p>

                {form.scope === 'shop' && form.wholeDay ? (
                  <div className="admin-field admin-field--full">
                    <label htmlFor={wholeDayDateId}>{t('scheduleBlocks.dateLabel')}</label>
                    <input
                      id={wholeDayDateId}
                      type="date"
                      value={form.wholeDayDate}
                      onChange={(event) => setForm((prev) => ({ ...prev, wholeDayDate: event.target.value }))}
                      required
                    />
                  </div>
                ) : (
                  <>
                    <div className="admin-field">
                      <label htmlFor={startsDateId}>{t('scheduleBlocks.startsDateLabel')}</label>
                      <input
                        id={startsDateId}
                        type="date"
                        value={form.starts_date}
                        onChange={(event) => setForm((prev) => ({ ...prev, starts_date: event.target.value }))}
                        required
                      />
                    </div>
                    <div className="admin-field">
                      <label htmlFor={startsTimeId}>{t('scheduleBlocks.startsTimeLabel')}</label>
                      <input
                        id={startsTimeId}
                        type="time"
                        value={form.starts_time}
                        onChange={(event) => setForm((prev) => ({ ...prev, starts_time: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="admin-field">
                      <label htmlFor={endsDateId}>{t('scheduleBlocks.endsDateLabel')}</label>
                      <input
                        id={endsDateId}
                        type="date"
                        value={form.ends_date}
                        onChange={(event) => setForm((prev) => ({ ...prev, ends_date: event.target.value }))}
                        required
                      />
                    </div>
                    <div className="admin-field">
                      <label htmlFor={endsTimeId}>{t('scheduleBlocks.endsTimeLabel')}</label>
                      <input
                        id={endsTimeId}
                        type="time"
                        value={form.ends_time}
                        onChange={(event) => setForm((prev) => ({ ...prev, ends_time: event.target.value }))}
                        required
                      />
                    </div>
                  </>
                )}

                <div className="admin-field admin-field--full">
                  <label htmlFor={reasonId}>{t('scheduleBlocks.reasonLabel')}</label>
                  <input
                    id={reasonId}
                    value={form.reason}
                    onChange={(event) => setForm((prev) => ({ ...prev, reason: event.target.value }))}
                  />
                </div>
              </div>

              <div className="admin-form-actions">
                <button type="submit" className="admin-button" disabled={submitting}>
                  {submitting
                    ? t('common.actions.saving')
                    : form.scope === 'shop'
                      ? t('scheduleBlocks.createClosureButton')
                      : t('scheduleBlocks.createBlockButton')}
                </button>
              </div>
            </form>
          )}
        </section>

        <section className="admin-card">
          <h2>{t('scheduleBlocks.listTitle')}</h2>

          {loading && <p>{t('common.actions.loading')}</p>}

          {loadError && (
            <div role="alert">
              <p className="admin-form-error">{loadError}</p>
              <button type="button" className="admin-button" onClick={loadAll}>
                {t('common.actions.tryAgain')}
              </button>
            </div>
          )}

          {blocks !== null && blocks.length === 0 && <p>{t('scheduleBlocks.emptyState')}</p>}

          {blocks !== null && blocks.length > 0 && (
            <div className="admin-table-wrapper">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th scope="col">{t('scheduleBlocks.tableScope')}</th>
                    <th scope="col">{t('scheduleBlocks.tableStart')}</th>
                    <th scope="col">{t('scheduleBlocks.tableEnd')}</th>
                    <th scope="col">{t('scheduleBlocks.tableReason')}</th>
                    <th scope="col">
                      <span className="sr-only">{t('common.actions.actionsColumn')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {blocks.map((item) =>
                    item.kind === 'shop' ? (
                      <tr key={`shop-${item.group_id}`}>
                        <td data-label={t('scheduleBlocks.tableScope')}>{t('scheduleBlocks.rowShop')}</td>
                        <td data-label={t('scheduleBlocks.tableStart')}>
                          {formatInstant(item.starts_at, timezone, i18n.language)}
                        </td>
                        <td data-label={t('scheduleBlocks.tableEnd')}>
                          {formatInstant(item.ends_at, timezone, i18n.language)}
                        </td>
                        <td data-label={t('scheduleBlocks.tableReason')}>{item.reason || t('common.emptyValue')}</td>
                        <td data-label={t('common.actions.actionsColumn')}>
                          <button
                            type="button"
                            className="admin-button admin-button--secondary"
                            onClick={() => handleDeleteGroup(item)}
                            disabled={removingKey === `shop-${item.group_id}`}
                          >
                            {removingKey === `shop-${item.group_id}`
                              ? t('common.actions.removing')
                              : t('scheduleBlocks.removeClosure')}
                          </button>
                        </td>
                      </tr>
                    ) : (
                      <tr key={`professional-${item.id}`}>
                        <td data-label={t('scheduleBlocks.tableScope')}>
                          {t('scheduleBlocks.rowProfessional', { name: item.professional.name })}
                        </td>
                        <td data-label={t('scheduleBlocks.tableStart')}>
                          {formatInstant(item.starts_at, timezone, i18n.language)}
                        </td>
                        <td data-label={t('scheduleBlocks.tableEnd')}>
                          {formatInstant(item.ends_at, timezone, i18n.language)}
                        </td>
                        <td data-label={t('scheduleBlocks.tableReason')}>{item.reason || t('common.emptyValue')}</td>
                        <td data-label={t('common.actions.actionsColumn')}>
                          <button
                            type="button"
                            className="admin-button admin-button--secondary"
                            onClick={() => handleDeleteIndividual(item)}
                            disabled={removingKey === `professional-${item.id}`}
                          >
                            {removingKey === `professional-${item.id}`
                              ? t('common.actions.removing')
                              : t('common.actions.remove')}
                          </button>
                        </td>
                      </tr>
                    )
                  )}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </AdminLayout>
  )
}

function formatInstant(iso, timezone, locale) {
  if (!timezone) return iso
  const { date, time } = utcIsoToZonedParts(iso, timezone)
  const [year, month, day] = date.split('-')

  return locale === 'en' ? `${month}/${day}/${year} ${time}` : `${day}/${month}/${year} ${time}`
}

function formatRange(item, timezone, locale) {
  return `${formatInstant(item.starts_at, timezone, locale)} – ${formatInstant(item.ends_at, timezone, locale)}`
}

export default ScheduleBlocks
