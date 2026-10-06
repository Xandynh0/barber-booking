import { useCallback, useEffect, useId, useState } from 'react'
import { AdminLayout } from '../../components/AdminLayout'
import { ApiError } from '../../api/client'
import { listServices } from '../../api/services'
import { createProfessional, listProfessionals, updateProfessional } from '../../api/professionals'
import './admin.css'

const emptyForm = { name: '', description: '', is_active: true, service_ids: [] }

function toFormState(professional) {
  return {
    name: professional.name,
    description: professional.description ?? '',
    is_active: professional.is_active,
    service_ids: professional.services.map((service) => service.id),
  }
}

function errorMessageFor(error) {
  if (error.code === 'NETWORK_ERROR') {
    return 'Não foi possível conectar ao servidor. Verifique sua conexão.'
  }
  if (error.code === 'NOT_FOUND') {
    return 'Este profissional não existe mais. Atualize a lista.'
  }

  return error.message || 'Não foi possível salvar. Tente novamente.'
}

function Professionals() {
  const [professionals, setProfessionals] = useState(null)
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
  const activeId = useId()

  const loadAll = useCallback(() => {
    setLoadError(null)
    setProfessionals(null)
    setServices(null)

    Promise.all([listProfessionals(), listServices()])
      .then(([professionalsBody, servicesBody]) => {
        setProfessionals(professionalsBody.data)
        setServices(servicesBody.data)
      })
      .catch((error) => {
        setLoadError(error instanceof ApiError ? errorMessageFor(error) : 'Não foi possível conectar ao servidor.')
      })
  }, [])

  useEffect(() => {
    loadAll()
  }, [loadAll])

  function startCreate() {
    setEditingId(null)
    setForm(emptyForm)
    setFieldErrors({})
    setFormError(null)
  }

  function startEdit(professional) {
    setEditingId(professional.id)
    setForm(toFormState(professional))
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)
  }

  function toggleService(serviceId) {
    setForm((prev) => ({
      ...prev,
      service_ids: prev.service_ids.includes(serviceId)
        ? prev.service_ids.filter((id) => id !== serviceId)
        : [...prev.service_ids, serviceId],
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (submitting) return

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)
    setSuccessMessage(null)

    const payload = {
      name: form.name,
      description: form.description === '' ? null : form.description,
      is_active: form.is_active,
      service_ids: form.service_ids,
    }

    try {
      if (editingId) {
        await updateProfessional(editingId, payload)
        setSuccessMessage('Profissional atualizado com sucesso.')
      } else {
        await createProfessional(payload)
        setSuccessMessage('Profissional criado com sucesso.')
      }
      startCreate()
      loadAll()
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

  const loading = professionals === null && services === null && !loadError

  return (
    <AdminLayout>
      <div className="admin-content">
        <section className="admin-card">
          <h2>{editingId ? 'Editar profissional' : 'Novo profissional'}</h2>

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

              <fieldset className="admin-field admin-field--full">
                <legend>Serviços oferecidos</legend>
                {services !== null && services.length === 0 && (
                  <p>Nenhum serviço cadastrado ainda — cadastre em "Serviços" primeiro.</p>
                )}
                {services !== null && services.length > 0 && (
                  <div className="admin-service-picker">
                    {services.map((service) => (
                      <label key={service.id} className="admin-service-picker-option">
                        <input
                          type="checkbox"
                          checked={form.service_ids.includes(service.id)}
                          onChange={() => toggleService(service.id)}
                        />
                        {service.name}
                        {!service.is_active && <span className="admin-tag-inactive"> (inativo)</span>}
                      </label>
                    ))}
                  </div>
                )}
                {fieldErrors.service_ids && (
                  <span className="admin-field-error">{fieldErrors.service_ids[0]}</span>
                )}
              </fieldset>

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
          <h2>Profissionais cadastrados</h2>

          {loading && <p>Carregando...</p>}

          {loadError && (
            <div role="alert">
              <p className="admin-form-error">{loadError}</p>
              <button type="button" className="admin-button" onClick={loadAll}>
                Tentar novamente
              </button>
            </div>
          )}

          {professionals !== null && professionals.length === 0 && <p>Nenhum profissional cadastrado ainda.</p>}

          {professionals !== null && professionals.length > 0 && (
            <div className="admin-table-wrapper">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Nome</th>
                    <th scope="col">Serviços</th>
                    <th scope="col">Status</th>
                    <th scope="col">
                      <span className="sr-only">Ações</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {professionals.map((professional) => (
                    <tr key={professional.id}>
                      <td data-label="Nome">{professional.name}</td>
                      <td data-label="Serviços">
                        {professional.services.length === 0 ? (
                          <span>Nenhum</span>
                        ) : (
                          professional.services.map((service, index) => (
                            <span key={service.id}>
                              {index > 0 && ', '}
                              {service.name}
                              {!service.is_active && <span className="admin-tag-inactive"> (inativo)</span>}
                            </span>
                          ))
                        )}
                      </td>
                      <td data-label="Status">
                        <span className="admin-status" data-active={professional.is_active}>
                          <span className="admin-status-dot" aria-hidden="true" />
                          {professional.is_active ? 'Ativo' : 'Inativo'}
                        </span>
                      </td>
                      <td data-label="Ações">
                        <button
                          type="button"
                          className="admin-button admin-button--secondary"
                          onClick={() => startEdit(professional)}
                        >
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

export default Professionals
