import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import ScheduleBlocks from './ScheduleBlocks'

vi.mock('../../api/auth')
vi.mock('../../api/professionals')
vi.mock('../../api/businessSettings')
vi.mock('../../api/scheduleBlocks')
import { me } from '../../api/auth'
import { listProfessionals } from '../../api/professionals'
import { getBusinessSettings } from '../../api/businessSettings'
import {
  createScheduleBlock,
  deleteScheduleBlock,
  deleteScheduleBlockGroup,
  listScheduleBlocks,
} from '../../api/scheduleBlocks'

function renderScheduleBlocks() {
  return render(
    <MemoryRouter initialEntries={['/admin/bloqueios']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route path="/admin/bloqueios" element={<ScheduleBlocks />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

const professional = { id: 1, name: 'Lucas', description: null, is_active: true, services: [] }

const individualBlock = {
  kind: 'professional',
  id: 10,
  professional: { id: 1, name: 'Lucas' },
  starts_at: '2026-12-24T21:00:00+00:00',
  ends_at: '2026-12-26T11:00:00+00:00',
  reason: 'Feriado',
}

const shopClosure = {
  kind: 'shop',
  group_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
  professionals: [
    { id: 1, name: 'Lucas' },
    { id: 2, name: 'João' },
  ],
  starts_at: '2026-12-25T03:00:00+00:00',
  ends_at: '2026-12-26T03:00:00+00:00',
  reason: 'Natal',
}

describe('ScheduleBlocks', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listProfessionals.mockResolvedValue({ data: [professional] })
    getBusinessSettings.mockResolvedValue({ data: { timezone: 'America/Sao_Paulo' } })
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows an empty state when there are no blocks', async () => {
    listScheduleBlocks.mockResolvedValue({ data: [] })

    renderScheduleBlocks()

    await waitFor(() => expect(screen.getByText('Nenhum bloqueio cadastrado ainda.')).toBeInTheDocument())
  })

  it('lists an individual block converted to the barbershop timezone and stating its scope explicitly', async () => {
    listScheduleBlocks.mockResolvedValue({ data: [individualBlock] })

    renderScheduleBlocks()

    expect(await screen.findByText('Profissional: Lucas')).toBeInTheDocument()
    expect(screen.getByText('24/12/2026 18:00')).toBeInTheDocument()
    expect(screen.getByText('26/12/2026 08:00')).toBeInTheDocument()
  })

  it('creates a professional-scoped block, converting local date/time to UTC', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({ data: [individualBlock] })
    createScheduleBlock.mockResolvedValue({ data: individualBlock })

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')

    await user.type(screen.getByLabelText('Início — data'), '2026-12-24')
    await user.type(screen.getByLabelText('Início — hora'), '18:00')
    await user.type(screen.getByLabelText('Fim — data'), '2026-12-26')
    await user.type(screen.getByLabelText('Fim — hora'), '08:00')
    await user.type(screen.getByLabelText('Motivo (opcional, uso interno)'), 'Feriado')

    await user.click(screen.getByRole('button', { name: 'Criar bloqueio' }))

    await waitFor(() =>
      expect(createScheduleBlock).toHaveBeenCalledWith({
        scope: 'professional',
        professional_id: 1,
        starts_at: new Date('2026-12-24T21:00:00.000Z').toISOString(),
        ends_at: new Date('2026-12-26T11:00:00.000Z').toISOString(),
        reason: 'Feriado',
      })
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Bloqueio criado com sucesso.')
  })

  it('shows the explanatory text and hides the professional field when "Fechamento da barbearia" is selected', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [] })

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')
    await user.click(screen.getByRole('radio', { name: 'Fechamento da barbearia' }))

    expect(screen.getByText('Nenhum profissional atenderá durante este período.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Profissional')).not.toBeInTheDocument()
  })

  it('creates a shop-wide closure with a custom interval and no professional field', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [] })
    createScheduleBlock.mockResolvedValue({ data: shopClosure })

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')
    await user.click(screen.getByRole('radio', { name: 'Fechamento da barbearia' }))

    await user.type(screen.getByLabelText('Início — data'), '2026-12-25')
    await user.type(screen.getByLabelText('Início — hora'), '00:00')
    await user.type(screen.getByLabelText('Fim — data'), '2026-12-25')
    await user.type(screen.getByLabelText('Fim — hora'), '23:59')
    await user.click(screen.getByRole('button', { name: 'Criar fechamento' }))

    await waitFor(() =>
      expect(createScheduleBlock).toHaveBeenCalledWith(expect.objectContaining({ scope: 'shop', reason: null }))
    )
    expect(createScheduleBlock.mock.calls[0][0]).not.toHaveProperty('professional_id')
    expect(await screen.findByRole('status')).toHaveTextContent('Fechamento criado com sucesso.')
  })

  it('creates a whole-day shop closure converting midnight-to-midnight in the barbershop timezone', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [] })
    createScheduleBlock.mockResolvedValue({ data: shopClosure })

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')
    await user.click(screen.getByRole('radio', { name: 'Fechamento da barbearia' }))
    await user.click(screen.getByLabelText('Dia inteiro'))
    await user.type(screen.getByLabelText('Data'), '2026-12-25')
    await user.click(screen.getByRole('button', { name: 'Criar fechamento' }))

    await waitFor(() =>
      expect(createScheduleBlock).toHaveBeenCalledWith(
        expect.objectContaining({
          scope: 'shop',
          starts_at: new Date('2026-12-25T03:00:00.000Z').toISOString(),
          ends_at: new Date('2026-12-26T03:00:00.000Z').toISOString(),
        })
      )
    )
  })

  it('shows a shop closure as a single item with a "Remover fechamento" action', async () => {
    listScheduleBlocks.mockResolvedValue({ data: [shopClosure] })

    renderScheduleBlocks()

    expect(await screen.findByRole('cell', { name: 'Fechamento da barbearia' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Remover fechamento' })).toBeInTheDocument()
    expect(screen.queryByText('Profissional: Lucas')).not.toBeInTheDocument()
  })

  it('lists an individual block and a shop closure side by side', async () => {
    listScheduleBlocks.mockResolvedValue({ data: [individualBlock, shopClosure] })

    renderScheduleBlocks()

    expect(await screen.findByText('Profissional: Lucas')).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'Fechamento da barbearia' })).toBeInTheDocument()
  })

  it('shows field validation errors and preserves typed values', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [] })
    createScheduleBlock.mockRejectedValue(
      new ApiError(422, 'VALIDATION_ERROR', 'Dados inválidos.', {
        professional_id: ['Profissional informado não existe.'],
      })
    )

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')
    await user.type(screen.getByLabelText('Início — data'), '2026-12-24')
    await user.type(screen.getByLabelText('Início — hora'), '18:00')
    await user.type(screen.getByLabelText('Fim — data'), '2026-12-26')
    await user.type(screen.getByLabelText('Fim — hora'), '08:00')
    await user.click(screen.getByRole('button', { name: 'Criar bloqueio' }))

    expect(await screen.findByText('Profissional informado não existe.')).toBeInTheDocument()
    expect(screen.getByLabelText('Início — data')).toHaveValue('2026-12-24')
  })

  it('asks for confirmation before removing an individual block, and removes it on confirmation', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [individualBlock] })
    deleteScheduleBlock.mockResolvedValue(null)
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderScheduleBlocks()

    await screen.findByText('Profissional: Lucas')
    await user.click(screen.getByRole('button', { name: 'Remover' }))

    expect(confirmSpy).toHaveBeenCalled()
    await waitFor(() => expect(deleteScheduleBlock).toHaveBeenCalledWith(10))
    await waitFor(() => expect(screen.getByText('Nenhum bloqueio cadastrado ainda.')).toBeInTheDocument())
  })

  it('does not remove an individual block when the confirmation is declined', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [individualBlock] })
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderScheduleBlocks()

    await screen.findByText('Profissional: Lucas')
    await user.click(screen.getByRole('button', { name: 'Remover' }))

    expect(deleteScheduleBlock).not.toHaveBeenCalled()
    expect(screen.getByText('Profissional: Lucas')).toBeInTheDocument()
  })

  it('confirms removal of a shop closure explaining which professionals are released, then removes the whole group', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [shopClosure] })
    deleteScheduleBlockGroup.mockResolvedValue(null)
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderScheduleBlocks()

    await screen.findByRole('cell', { name: 'Fechamento da barbearia' })
    await user.click(screen.getByRole('button', { name: 'Remover fechamento' }))

    expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining('Lucas, João'))
    expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining('libera'))
    await waitFor(() => expect(deleteScheduleBlockGroup).toHaveBeenCalledWith(shopClosure.group_id))
    await waitFor(() => expect(screen.getByText('Nenhum bloqueio cadastrado ainda.')).toBeInTheDocument())
  })

  it('does not remove a shop closure when the confirmation is declined', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [shopClosure] })
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    renderScheduleBlocks()

    await screen.findByRole('cell', { name: 'Fechamento da barbearia' })
    await user.click(screen.getByRole('button', { name: 'Remover fechamento' }))

    expect(deleteScheduleBlockGroup).not.toHaveBeenCalled()
    expect(screen.getByRole('cell', { name: 'Fechamento da barbearia' })).toBeInTheDocument()
  })

  it('removing a shop closure does not remove an unrelated individual block from the screen', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [individualBlock, shopClosure] })
    deleteScheduleBlockGroup.mockResolvedValue(null)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    renderScheduleBlocks()

    await screen.findByRole('cell', { name: 'Fechamento da barbearia' })
    await user.click(screen.getByRole('button', { name: 'Remover fechamento' }))

    await waitFor(() => expect(screen.queryByRole('cell', { name: 'Fechamento da barbearia' })).not.toBeInTheDocument())
    expect(screen.getByText('Profissional: Lucas')).toBeInTheDocument()
  })

  it('submits the same UTC instant for a whole-day closure regardless of the UI language', async () => {
    const user = userEvent.setup()
    listScheduleBlocks.mockResolvedValue({ data: [] })
    createScheduleBlock.mockResolvedValue({ data: shopClosure })

    renderScheduleBlocks()

    await screen.findByText('Nenhum bloqueio cadastrado ainda.')
    await user.click(screen.getByRole('button', { name: 'English' }))

    await user.click(screen.getByRole('radio', { name: 'Barbershop closure' }))
    await user.click(screen.getByLabelText('Whole day'))
    await user.type(screen.getByLabelText('Date'), '2026-12-25')
    await user.click(screen.getByRole('button', { name: 'Create closure' }))

    await waitFor(() =>
      expect(createScheduleBlock).toHaveBeenCalledWith(
        expect.objectContaining({
          scope: 'shop',
          // Same instants the pt-BR "whole day" test expects (see the
          // backend's equivalent test): the UI language only changes
          // labels/formatting, never the computed UTC instant.
          starts_at: new Date('2026-12-25T03:00:00.000Z').toISOString(),
          ends_at: new Date('2026-12-26T03:00:00.000Z').toISOString(),
        })
      )
    )
  })
})
