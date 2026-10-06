import { useCallback, useEffect, useId, useState } from 'react'
import { AdminLayout } from '../../components/AdminLayout'
import { ApiError } from '../../api/client'
import { createService, listServices, updateService } from '../../api/services'
import { parseBRLInput, toBRLInput } from '../../utils/money'
import './admin.css'

const emptyForm = { name: '', description: '', duration_minutes: '', price: '', is_active: true }

function toFormState(service) {
  return {
    name: service.name,
    description: service.description ?? '',
    duration_minutes: String(service.duration_minutes),
    price: toBRLInput(service.price),
    is_active: service.is_active,
  }
}

function errorMessageFor(error) {
  if (error.code === 'NETWORK_ERROR') {
    return 'Não foi possível conectar ao servidor. Verifique sua conexão.'
  }
  if (error.code === 'NOT_FOUND') {
    return 'Este serviço não existe mais. Atualize a lista.'
  }

  return error.message || 'Não foi possível salvar. Tente novamente.'
}

function Services() {
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

  const loadServices = useCallback(() => {
    setLoadError(null)
    setServices(null)

    listServices()
      .then((body) => setServices(body.data))
      .catch((error) => {
        setLoadError(error instanceof ApiError ? errorMessageFor(error) : 'Não foi possível conectar ao servidor.')
      })
  }, [])

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
    setForm(toFormState(service))
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    const price = parseBRLInput(form.price)
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
        setSuccessMessage('Serviço atualizado com sucesso.')
      } else {
        await createService(payload)
        setSuccessMessage('Serviço criado com sucesso.')
      }
      startCreate()
      loadServices()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        setFieldErrors(error.fields ?? {})
      } else if (error instanceof ApiError) {
        setFormError(errorMessageFor(error))
      } else {
        setFormError('Não foi possível salvar. Tente novamente.')
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
          <h2>{editingId ? 'Editar serviço' : 'Novo serviço'}</h2>

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
                <label htmlFor={nameId}>Nome</label>
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
                <label htmlFor={descriptionId}>Descrição (opcional)</label>
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
                <label htmlFor={durationId}>Duração (minutos)</label>
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
                <label htmlFor={priceId}>Preço (R$)</label>
                <input
                  id={priceId}
                  inputMode="decimal"
                  placeholder="0,00"
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
                <label htmlFor={activeId}>Ativo</label>
              </div>
            </div>

            <div className="admin-form-actions">
              <button type="submit" className="admin-button" disabled={submitting}>
                {submitting ? 'Salvando...' : editingId ? 'Salvar' : 'Criar'}
              </button>
              {editingId && (
                <button type="button" className="admin-button admin-button--secondary" onClick={startCreate}>
                  Cancelar
                </button>
              )}
            </div>
          </form>
        </section>

        <section className="admin-card">
          <h2>Serviços cadastrados</h2>

          {services === null && !loadError && <p>Carregando...</p>}

          {loadError && (
            <div role="alert">
              <p className="admin-form-error">{loadError}</p>
              <button type="button" className="admin-button" onClick={loadServices}>
                Tentar novamente
              </button>
            </div>
          )}

          {services !== null && services.length === 0 && <p>Nenhum serviço cadastrado ainda.</p>}

          {services !== null && services.length > 0 && (
            <div className="admin-table-wrapper">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Duração</th>
                    <th scope="col">Preço</th>
                    <th scope="col">Status</th>
                    <th scope="col">
                      <span className="sr-only">Ações</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {services.map((service) => (
                    <tr key={service.id}>
                      <td data-label="Nome">{service.name}</td>
                      <td data-label="Duração">{service.duration_minutes} min</td>
                      <td data-label="Preço">R$ {toBRLInput(service.price)}</td>
                      <td data-label="Status">
                        <span className="admin-status" data-active={service.is_active}>
                          <span className="admin-status-dot" aria-hidden="true" />
                          {service.is_active ? 'Ativo' : 'Inativo'}
                        </span>
                      </td>
                      <td data-label="Ações">
                        <button type="button" className="admin-button admin-button--secondary" onClick={() => startEdit(service)}>
                          Editar
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
