import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AdminLayout } from '../../components/AdminLayout'
import { ApiError } from '../../api/client'
import { createService, listServices, updateService } from '../../api/services'
import { parseMoneyInput, swapMoneySeparator, toMoneyInput } from '../../utils/money'
import './admin.css'

const emptyForm = { name: '', description: '', duration_minutes: '', price: '', is_active: true }

function toFormState(service, locale) {
  return {
    name: service.name,
    description: service.description ?? '',
    duration_minutes: String(service.duration_minutes),
    price: toMoneyInput(service.price, locale),
    is_active: service.is_active,
  }
}

function errorMessageFor(t, error) {
  if (error.code === 'NETWORK_ERROR') {
    return t('services.errors.network')
  }
  if (error.code === 'NOT_FOUND') {
    return t('services.errors.notFound')
  }

  return error.message || t('services.errors.generic')
}

function Services() {
  const { t, i18n } = useTranslation()
  const [services, setServices] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [successMessage, setSuccessMessage] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const nameId = useId()
  const descriptionId = useId()
  const durationId = useId()
  const priceId = useId()
  const activeId = useId()

  const previousLanguage = useRef(i18n.language)

  // Reformats the price field to the new language's convention (comma vs.
  // dot decimal) WITHOUT changing the monetary value — including an
  // incomplete, still-being-typed entry — so switching language never
  // discards or misreads what the admin already typed.
  useEffect(() => {
    const fromLanguage = previousLanguage.current
    const toLanguage = i18n.language

    if (fromLanguage !== toLanguage) {
      // Captured as fixed locals above — not read back off the ref inside
      // the updater, which React may invoke after the synchronous line
      // below has already moved the ref on to `toLanguage` (making the
      // swap a same-language no-op).
      setForm((prev) => ({ ...prev, price: swapMoneySeparator(prev.price, fromLanguage, toLanguage) }))
      previousLanguage.current = toLanguage
    }
  }, [i18n.language])

  const loadServices = useCallback(() => {
    setLoadError(null)
    setServices(null)

    listServices()
      .then((body) => setServices(body.data))
      .catch((error) => {
        setLoadError(error instanceof ApiError ? errorMessageFor(t, error) : t('services.errors.network'))
      })
  }, [t])

  useEffect(() => {
    loadServices()
  }, [loadServices])

  function startCreate() {
    setEditingId(null)
    setForm(emptyForm)
    setFieldErrors({})
    setFormError(null)
  }

  function startEdit(service) {
    setEditingId(service.id)
    setForm(toFormState(service, i18n.language))
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const price = parseMoneyInput(form.price, i18n.language)
    const duration = Number(form.duration_minutes)

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)

    const payload = {
      name: form.name,
      description: form.description === '' ? null : form.description,
      duration_minutes: Number.isFinite(duration) ? duration : form.duration_minutes,
      price: price ?? form.price,
      is_active: form.is_active,
    }

    try {
      if (editingId) {
        await updateService(editingId, payload)
        setSuccessMessage(t('services.updatedSuccess'))
      } else {
        await createService(payload)
        setSuccessMessage(t('services.createdSuccess'))
      }
      startCreate()
      loadServices()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        setFieldErrors(error.fields ?? {})
      } else if (error instanceof ApiError) {
        setFormError(errorMessageFor(t, error))
      } else {
        setFormError(t('services.errors.generic'))
      }
      // Form values are intentionally left untouched on failure.
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AdminLayout>
      <div className="admin-content">
        <section className="admin-card">
          <h2>{editingId ? t('services.editTitle') : t('services.newTitle')}</h2>

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

          <form onSubmit={handleSubmit} noValidate>
            <div className="admin-form-grid">
              <div className="admin-field admin-field--full">
                <label htmlFor={nameId}>{t('common.fields.name')}</label>
                <input
                  id={nameId}
                  value={form.name}
                  onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                  aria-invalid={Boolean(fieldErrors.name)}
                  aria-describedby={fieldErrors.name ? `${nameId}-error` : undefined}
                  required
                />
                {fieldErrors.name && (
                  <span id={`${nameId}-error`} className="admin-field-error">
                    {fieldErrors.name[0]}
                  </span>
                )}
              </div>

              <div className="admin-field admin-field--full">
                <label htmlFor={descriptionId}>{t('common.fields.descriptionOptional')}</label>
                <textarea
                  id={descriptionId}
                  value={form.description}
                  onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))}
                  aria-invalid={Boolean(fieldErrors.description)}
                  aria-describedby={fieldErrors.description ? `${descriptionId}-error` : undefined}
                />
                {fieldErrors.description && (
                  <span id={`${descriptionId}-error`} className="admin-field-error">
                    {fieldErrors.description[0]}
                  </span>
                )}
              </div>

              <div className="admin-field">
                <label htmlFor={durationId}>{t('services.durationLabel')}</label>
                <input
                  id={durationId}
                  type="number"
                  min="1"
                  inputMode="numeric"
                  value={form.duration_minutes}
                  onChange={(event) => setForm((prev) => ({ ...prev, duration_minutes: event.target.value }))}
                  aria-invalid={Boolean(fieldErrors.duration_minutes)}
                  aria-describedby={fieldErrors.duration_minutes ? `${durationId}-error` : undefined}
                  required
                />
                {fieldErrors.duration_minutes && (
                  <span id={`${durationId}-error`} className="admin-field-error">
                    {fieldErrors.duration_minutes[0]}
                  </span>
                )}
              </div>

              <div className="admin-field">
                <label htmlFor={priceId}>{t('services.priceLabel')}</label>
                <input
                  id={priceId}
                  inputMode="decimal"
                  placeholder={t('services.pricePlaceholder')}
                  value={form.price}
                  onChange={(event) => setForm((prev) => ({ ...prev, price: event.target.value }))}
                  aria-invalid={Boolean(fieldErrors.price)}
                  aria-describedby={fieldErrors.price ? `${priceId}-error` : undefined}
                  required
                />
                {fieldErrors.price && (
                  <span id={`${priceId}-error`} className="admin-field-error">
                    {fieldErrors.price[0]}
                  </span>
                )}
              </div>

              <div className="admin-field admin-field-checkbox admin-field--full">
                <input
                  id={activeId}
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(event) => setForm((prev) => ({ ...prev, is_active: event.target.checked }))}
                />
                <label htmlFor={activeId}>{t('common.fields.statusActive')}</label>
              </div>
            </div>

            <div className="admin-form-actions">
              <button type="submit" className="admin-button" disabled={submitting}>
                {submitting ? t('common.actions.saving') : editingId ? t('common.actions.save') : t('common.actions.create')}
              </button>
              {editingId && (
                <button type="button" className="admin-button admin-button--secondary" onClick={startCreate}>
                  {t('common.actions.cancel')}
                </button>
              )}
            </div>
          </form>
        </section>

        <section className="admin-card">
          <h2>{t('services.listTitle')}</h2>

          {services === null && !loadError && <p>{t('common.actions.loading')}</p>}

          {loadError && (
            <div role="alert">
              <p className="admin-form-error">{loadError}</p>
              <button type="button" className="admin-button" onClick={loadServices}>
                {t('common.actions.tryAgain')}
              </button>
            </div>
          )}

          {services !== null && services.length === 0 && <p>{t('services.emptyState')}</p>}

          {services !== null && services.length > 0 && (
            <div className="admin-table-wrapper">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th scope="col">{t('common.fields.name')}</th>
                    <th scope="col">{t('services.tableDuration')}</th>
                    <th scope="col">{t('services.tablePrice')}</th>
                    <th scope="col">{t('common.fields.status')}</th>
                    <th scope="col">
                      <span className="sr-only">{t('common.actions.actionsColumn')}</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {services.map((service) => (
                    <tr key={service.id}>
                      <td data-label={t('common.fields.name')}>{service.name}</td>
                      <td data-label={t('services.tableDuration')}>
                        {t('services.durationUnit', { count: service.duration_minutes })}
                      </td>
                      <td data-label={t('services.tablePrice')}>
                        {t('services.price', { price: toMoneyInput(service.price, i18n.language) })}
                      </td>
                      <td data-label={t('common.fields.status')}>
                        <span className="admin-status" data-active={service.is_active}>
                          <span className="admin-status-dot" aria-hidden="true" />
                          {service.is_active ? t('common.fields.statusActive') : t('common.fields.statusInactive')}
                        </span>
                      </td>
                      <td data-label={t('common.actions.actionsColumn')}>
                        <button type="button" className="admin-button admin-button--secondary" onClick={() => startEdit(service)}>
                          {t('common.actions.edit')}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </div>
    </AdminLayout>
  )
}

export default Services
