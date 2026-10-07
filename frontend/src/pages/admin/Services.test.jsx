import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import Services from './Services'

vi.mock('../../api/auth')
vi.mock('../../api/services')
import { me } from '../../api/auth'
import { createService, listServices, updateService } from '../../api/services'

function renderServices() {
  return render(
    <MemoryRouter initialEntries={['/admin/servicos']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route path="/admin/servicos" element={<Services />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

const service = {
  id: 1,
  name: 'Corte masculino',
  description: null,
  duration_minutes: 30,
  price: '45.00',
  is_active: true,
};

describe('Services', () => {
  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows a loading state, then the list of services', async () => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [service] })

    renderServices()

    await waitFor(() => expect(screen.getByText('Corte masculino')).toBeInTheDocument())
    expect(screen.getByText('R$ 45,00')).toBeInTheDocument()
    expect(screen.getByRole('cell', { name: 'Ativo' })).toBeInTheDocument()
  })

  it('shows an empty state when there are no services', async () => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })

    renderServices()

    await waitFor(() => expect(screen.getByText('Nenhum serviço cadastrado ainda.')).toBeInTheDocument())
  })

  it('shows an error with a retry button when the list fails to load', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices
      .mockRejectedValueOnce(new ApiError(0, 'NETWORK_ERROR', 'Não foi possível conectar ao servidor.'))
      .mockResolvedValueOnce({ data: [service] })

    renderServices()

    await screen.findByRole('alert')
    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }))

    await waitFor(() => expect(screen.getByText('Corte masculino')).toBeInTheDocument())
  })

  it('creates a service, converting the Brazilian money input to the API decimal format', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({ data: [service] })
    createService.mockResolvedValue({ data: service })

    renderServices()

    await screen.findByText('Nenhum serviço cadastrado ainda.')

    await user.type(screen.getByLabelText('Nome'), 'Corte masculino')
    await user.type(screen.getByLabelText('Duração (minutos)'), '30')
    await user.type(screen.getByLabelText('Preço (R$)'), '45,00')
    await user.click(screen.getByRole('button', { name: 'Criar' }))

    await waitFor(() => expect(createService).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Corte masculino', duration_minutes: 30, price: '45.00' })
    ))
    expect(await screen.findByRole('status')).toHaveTextContent('Serviço criado com sucesso.')
  })

  it('shows field validation errors and preserves the typed values', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })
    createService.mockRejectedValue(
      new ApiError(422, 'VALIDATION_ERROR', 'Dados inválidos.', {
        duration_minutes: ['The duration minutes field must be at least 1.'],
      })
    )

    renderServices()

    await screen.findByText('Nenhum serviço cadastrado ainda.')

    await user.type(screen.getByLabelText('Nome'), 'Corte')
    await user.type(screen.getByLabelText('Duração (minutos)'), '0')
    await user.type(screen.getByLabelText('Preço (R$)'), '10,00')
    await user.click(screen.getByRole('button', { name: 'Criar' }))

    expect(await screen.findByText('The duration minutes field must be at least 1.')).toBeInTheDocument()
    expect(screen.getByLabelText('Nome')).toHaveValue('Corte')
    expect(screen.getByLabelText('Duração (minutos)')).toHaveValue(0)
  })

  it('edits an existing service, prefilling the form and sending a PATCH', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [service] })
    updateService.mockResolvedValue({ data: { ...service, price: '50.00' } })

    renderServices()

    await screen.findByText('Corte masculino')
    await user.click(screen.getByRole('button', { name: 'Editar' }))

    expect(screen.getByLabelText('Preço (R$)')).toHaveValue('45,00')

    await user.clear(screen.getByLabelText('Preço (R$)'))
    await user.type(screen.getByLabelText('Preço (R$)'), '50,00')
    await user.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() => expect(updateService).toHaveBeenCalledWith(1, expect.objectContaining({ price: '50.00' })))
  })

  it('preserves a typed price value (including an incomplete one) when the language is switched', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })

    renderServices()

    await screen.findByText('Nenhum serviço cadastrado ainda.')
    await user.type(screen.getByLabelText('Preço (R$)'), '45,90')

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByLabelText('Price (BRL, R$)')).toHaveValue('45.90')

    await user.click(screen.getByRole('button', { name: 'Português' }))

    expect(await screen.findByLabelText('Preço (R$)')).toHaveValue('45,90')
  })

  it('preserves an incomplete price entry (no misreading of the typed digits) across a language switch', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })

    renderServices()

    await screen.findByText('Nenhum serviço cadastrado ainda.')
    await user.type(screen.getByLabelText('Preço (R$)'), '45,')

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByLabelText('Price (BRL, R$)')).toHaveValue('45.')
  })
})
