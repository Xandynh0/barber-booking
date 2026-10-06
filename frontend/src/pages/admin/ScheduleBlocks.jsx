import { useCallback, useEffect, useId, useState } from 'react'
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

function errorMessageFor(error) {
  if (error.code === 'NETWORK_ERROR') {
    return 'Não foi possível conectar ao servidor. Verifique sua conexão.'
  }
  if (error.code === 'NOT_FOUND') {
    return 'Este bloqueio não existe mais. Atualize a lista.'
  }

  return error.message || 'Não foi possível salvar. Tente novamente.'
}

function ScheduleBlocks() {
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
        setLoadError(error instanceof ApiError ? errorMessageFor(error) : 'Não foi possível conectar ao servidor.')
      })
  }, [])

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
      setSuccessMessage(form.scope === 'shop' ? 'Fechamento criado com sucesso.' : 'Bloqueio criado com sucesso.')
      setForm((prev) => ({ ...emptyForm, scope: prev.scope, professional_id: prev.professional_id }))
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

  async function handleDeleteIndividual(item) {
    const confirmed = window.confirm(
      `Remover o bloqueio de ${item.professional.name} (${formatRange(item, timezone)})? Esta ação não pode ser desfeita.`
    )
    if (!confirmed) return

    setRemovingKey(`professional-${item.id}`)
    setFormError(null)

    try {
      await deleteScheduleBlock(item.id)
      setBlocks((prev) => prev.filter((b) => !(b.kind === 'professional' && b.id === item.id)))
    } catch (error) {
      setFormError(error instanceof ApiError ? errorMessageFor(error) : 'Não foi possível remover. Tente novamente.')
    } finally {
      setRemovingKey(null)
    }
  }

  async function handleDeleteGroup(item) {
    const names = item.professionals.map((p) => p.name).join(', ')
    const confirmed = window.confirm(
      `Remover o fechamento da barbearia (${formatRange(item, timezone)})? ` +
        `Isso libera ${item.professionals.length} profissional(is) deste fechamento: ${names}. ` +
        'Outros bloqueios que coincidam com este período continuam valendo. Esta ação não pode ser desfeita.'
    )
    if (!confirmed) return

    setRemovingKey(`shop-${item.group_id}`)
    setFormError(null)

    try {
      await deleteScheduleBlockGroup(item.group_id)
      setBlocks((prev) => prev.filter((b) => !(b.kind === 'shop' && b.group_id === item.group_id)))
    } catch (error) {
      setFormError(error instanceof ApiError ? errorMessageFor(error) : 'Não foi possível remover. Tente novamente.')
    } finally {
      setRemovingKey(null)
    }
  }

  const loading = blocks === null && !loadError

  return (
    <AdminLayout>
      <div className="admin-content">
        <section className="admin-card">
          <h2>Novo bloqueio</h2>

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

          {professionals !== null && professionals.length === 0 && (
            <p>Nenhum profissional cadastrado ainda — cadastre em "Profissionais" primeiro.</p>
          )}

          {professionals !== null && professionals.length > 0 && (
            <form onSubmit={handleSubmit} noValidate>
              <div className="admin-form-grid">
                <fieldset className="admin-field admin-field--full">
                  <legend>Alcance do bloqueio</legend>
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
                      Profissional específico
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
                      Fechamento da barbearia
                    </label>
                  </div>
                  {form.scope === 'shop' && (
                    <p className="admin-hint">Nenhum profissional atenderá durante este período.</p>
                  )}
                </fieldset>

                {form.scope === 'professional' && (
                  <div className="admin-field admin-field--full">
                    <label htmlFor={professionalSelectId}>Profissional</label>
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
                    <label htmlFor={wholeDayId}>Dia inteiro</label>
                  </div>
                )}

                <p className="admin-hint admin-field--full">Horários no fuso da barbearia ({timezone}).</p>

                {form.scope === 'shop' && form.wholeDay ? (
                  <div className="admin-field admin-field--full">
                    <label htmlFor={wholeDayDateId}>Data</label>
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
                      <label htmlFor={startsDateId}>Início — data</label>
                      <input
                        id={startsDateId}
                        type="date"
                        value={form.starts_date}
                        onChange={(event) => setForm((prev) => ({ ...prev, starts_date: event.target.value }))}
                        required
                      />
                    </div>
                    <div className="admin-field">
                      <label htmlFor={startsTimeId}>Início — hora</label>
                      <input
                        id={startsTimeId}
                        type="time"
                        value={form.starts_time}
                        onChange={(event) => setForm((prev) => ({ ...prev, starts_time: event.target.value }))}
                        required
                      />
                    </div>

                    <div className="admin-field">
                      <label htmlFor={endsDateId}>Fim — data</label>
                      <input
                        id={endsDateId}
                        type="date"
                        value={form.ends_date}
                        onChange={(event) => setForm((prev) => ({ ...prev, ends_date: event.target.value }))}
                        required
                      />
                    </div>
                    <div className="admin-field">
                      <label htmlFor={endsTimeId}>Fim — hora</label>
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
                  <label htmlFor={reasonId}>Motivo (opcional, uso interno)</label>
                  <input
                    id={reasonId}
                    value={form.reason}
                    onChange={(event) => setForm((prev) => ({ ...prev, reason: event.target.value }))}
                  />
                </div>
              </div>

              <div className="admin-form-actions">
                <button type="submit" className="admin-button" disabled={submitting}>
                  {submitting ? 'Salvando...' : form.scope === 'shop' ? 'Criar fechamento' : 'Criar bloqueio'}
                </button>
              </div>
            </form>
          )}
        </section>

        <section className="admin-card">
          <h2>Bloqueios cadastrados</h2>

          {loading && <p>Carregando...</p>}

          {loadError && (
            <div role="alert">
              <p className="admin-form-error">{loadError}</p>
              <button type="button" className="admin-button" onClick={loadAll}>
                Tentar novamente
              </button>
            </div>
          )}

          {blocks !== null && blocks.length === 0 && <p>Nenhum bloqueio cadastrado ainda.</p>}

          {blocks !== null && blocks.length > 0 && (
            <div className="admin-table-wrapper">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th scope="col">Alcance</th>
                    <th scope="col">Início</th>
                    <th scope="col">Fim</th>
                    <th scope="col">Motivo</th>
                    <th scope="col">
                      <span className="sr-only">Ações</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {blocks.map((item) =>
                    item.kind === 'shop' ? (
                      <tr key={`shop-${item.group_id}`}>
                        <td data-label="Alcance">Fechamento da barbearia</td>
                        <td data-label="Início">{formatInstant(item.starts_at, timezone)}</td>
                        <td data-label="Fim">{formatInstant(item.ends_at, timezone)}</td>
                        <td data-label="Motivo">{item.reason || '—'}</td>
                        <td data-label="Ações">
                          <button
                            type="button"
                            className="admin-button admin-button--secondary"
                            onClick={() => handleDeleteGroup(item)}
                            disabled={removingKey === `shop-${item.group_id}`}
                          >
                            {removingKey === `shop-${item.group_id}` ? 'Removendo...' : 'Remover fechamento'}
                          </button>
                        </td>
                      </tr>
                    ) : (
                      <tr key={`professional-${item.id}`}>
                        <td data-label="Alcance">Profissional: {item.professional.name}</td>
                        <td data-label="Início">{formatInstant(item.starts_at, timezone)}</td>
                        <td data-label="Fim">{formatInstant(item.ends_at, timezone)}</td>
                        <td data-label="Motivo">{item.reason || '—'}</td>
                        <td data-label="Ações">
                          <button
                            type="button"
                            className="admin-button admin-button--secondary"
                            onClick={() => handleDeleteIndividual(item)}
                            disabled={removingKey === `professional-${item.id}`}
                          >
                            {removingKey === `professional-${item.id}` ? 'Removendo...' : 'Remover'}
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

function formatInstant(iso, timezone) {
  if (!timezone) return iso
  const { date, time } = utcIsoToZonedParts(iso, timezone)
  const [year, month, day] = date.split('-')
  return `${day}/${month}/${year} ${time}`
}

function formatRange(item, timezone) {
  return `${formatInstant(item.starts_at, timezone)} – ${formatInstant(item.ends_at, timezone)}`
}

export default ScheduleBlocks
