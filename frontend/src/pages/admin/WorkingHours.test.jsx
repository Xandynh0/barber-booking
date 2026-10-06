import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import WorkingHours from './WorkingHours'

vi.mock('../../api/auth')
vi.mock('../../api/professionals')
vi.mock('../../api/workingHours')
import { me } from '../../api/auth'
import { listProfessionals } from '../../api/professionals'
import { getWorkingHours, updateWorkingHours } from '../../api/workingHours'

function renderWorkingHours() {
  return render(
    <MemoryRouter initialEntries={['/admin/expediente']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route path="/admin/expediente" element={<WorkingHours />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

const professional = { id: 1, name: 'Lucas', description: null, is_active: true, services: [] }

function emptyWeekData() {
  return {
    professional_id: 1,
    days: Array.from({ length: 7 }, (_, weekday) => ({ weekday, periods: [] })),
  }
}

describe('WorkingHours', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listProfessionals.mockResolvedValue({ data: [professional] })
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows a day off for a weekday with no periods and lets you add one', async () => {
    getWorkingHours.mockResolvedValue({ data: emptyWeekData() })

    renderWorkingHours()

    expect(await screen.findByText('Domingo')).toBeInTheDocument()
    expect(screen.getAllByText('Folga — nenhum período definido.')).toHaveLength(7)
  })

  it('shows the lunch-break periods already saved for the selected professional', async () => {
    const data = emptyWeekData()
    data.days[1].periods = [
      { start_time: '09:00', end_time: '12:00' },
      { start_time: '13:00', end_time: '18:00' },
    ]
    getWorkingHours.mockResolvedValue({ data })

    renderWorkingHours()

    await screen.findByText('Segunda-feira')
    const startInputs = screen.getAllByLabelText('Início')
    expect(startInputs[0]).toHaveValue('09:00')
    expect(startInputs[1]).toHaveValue('13:00')
  })

  it('adds and removes a period, then saves', async () => {
    const user = userEvent.setup()
    getWorkingHours.mockResolvedValue({ data: emptyWeekData() })
    const saved = emptyWeekData()
    saved.days[0].periods = [{ start_time: '09:00', end_time: '12:00' }]
    updateWorkingHours.mockResolvedValue({ data: saved })

    renderWorkingHours()

    await screen.findByText('Domingo')
    const addButtons = screen.getAllByRole('button', { name: 'Adicionar período' })
    await user.click(addButtons[0])

    const startInputs = screen.getAllByLabelText('Início')
    await user.type(startInputs[0], '09:00')
    const endInputs = screen.getAllByLabelText('Fim')
    await user.type(endInputs[0], '12:00')

    await user.click(screen.getByRole('button', { name: 'Salvar expediente' }))

    await waitFor(() =>
      expect(updateWorkingHours).toHaveBeenCalledWith(
        '1',
        expect.objectContaining({
          days: expect.arrayContaining([
            expect.objectContaining({ weekday: 0, periods: [{ start_time: '09:00', end_time: '12:00' }] }),
          ]),
        })
      )
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Expediente salvo com sucesso.')
  })

  it('shows a validation error grouped under the affected day without discarding the form', async () => {
    const user = userEvent.setup()
    getWorkingHours.mockResolvedValue({ data: emptyWeekData() })
    updateWorkingHours.mockRejectedValue(
      new ApiError(422, 'VALIDATION_ERROR', 'Dados inválidos.', {
        'days.1.periods.0.end_time': ['O fim deve ser depois do início.'],
      })
    )

    renderWorkingHours()

    await screen.findByText('Domingo')
    const addButtons = screen.getAllByRole('button', { name: 'Adicionar período' })
    await user.click(addButtons[1])
    await user.click(screen.getByRole('button', { name: 'Salvar expediente' }))

    expect(await screen.findByText('O fim deve ser depois do início.')).toBeInTheDocument()
  })

  it('shows an error with a retry button when the schedule fails to load', async () => {
    const user = userEvent.setup()
    getWorkingHours
      .mockRejectedValueOnce(new ApiError(0, 'NETWORK_ERROR', 'Não foi possível conectar ao servidor.'))
      .mockResolvedValueOnce({ data: emptyWeekData() })

    renderWorkingHours()

    await screen.findByRole('alert')
    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }))

    await waitFor(() => expect(screen.getByText('Domingo')).toBeInTheDocument())
  })
})
