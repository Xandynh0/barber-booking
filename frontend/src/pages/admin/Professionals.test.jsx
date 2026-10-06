import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import Professionals from './Professionals'

vi.mock('../../api/auth')
vi.mock('../../api/services')
vi.mock('../../api/professionals')
import { me } from '../../api/auth'
import { listServices } from '../../api/services'
import { createProfessional, listProfessionals, updateProfessional } from '../../api/professionals'

function renderProfessionals() {
  return render(
    <MemoryRouter initialEntries={['/admin/profissionais']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route path="/admin/profissionais" element={<Professionals />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

const activeService = { id: 1, name: 'Corte', is_active: true }
const inactiveService = { id: 2, name: 'Sobrancelha', is_active: false }

const professional = {
  id: 1,
  name: 'Lucas',
  description: null,
  is_active: true,
  services: [activeService],
}

describe('Professionals', () => {
  afterEach(() => {
    vi.resetAllMocks()
  })

  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
  })

  it('shows the list of professionals with their linked services', async () => {
    listProfessionals.mockResolvedValue({ data: [professional] })
    listServices.mockResolvedValue({ data: [activeService] })

    renderProfessionals()

    await waitFor(() => expect(screen.getByText('Lucas')).toBeInTheDocument())
    expect(screen.getByRole('cell', { name: 'Corte' })).toBeInTheDocument()
  })

  it('flags a linked service that has since become inactive', async () => {
    listProfessionals.mockResolvedValue({
      data: [{ ...professional, services: [inactiveService] }],
    })
    listServices.mockResolvedValue({ data: [inactiveService] })

    renderProfessionals()

    await waitFor(() => expect(screen.getByText('Lucas')).toBeInTheDocument())
    expect(screen.getByRole('cell', { name: /Sobrancelha\s*\(inativo\)/ })).toBeInTheDocument()
  })

  it('shows an empty state when there are no professionals', async () => {
    listProfessionals.mockResolvedValue({ data: [] })
    listServices.mockResolvedValue({ data: [] })

    renderProfessionals()

    await waitFor(() => expect(screen.getByText('Nenhum profissional cadastrado ainda.')).toBeInTheDocument())
  })

  it('creates a professional with the selected services', async () => {
    const user = userEvent.setup()
    listProfessionals.mockResolvedValueOnce({ data: [] }).mockResolvedValueOnce({ data: [professional] })
    listServices.mockResolvedValue({ data: [activeService, inactiveService] })
    createProfessional.mockResolvedValue({ data: professional })

    renderProfessionals()

    await screen.findByText('Nenhum profissional cadastrado ainda.')

    await user.type(screen.getByLabelText('Nome'), 'Lucas')
    await user.click(screen.getByRole('checkbox', { name: /Corte/ }))
    await user.click(screen.getByRole('button', { name: 'Criar' }))

    await waitFor(() =>
      expect(createProfessional).toHaveBeenCalledWith(
        expect.objectContaining({ name: 'Lucas', service_ids: [activeService.id] })
      )
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Profissional criado com sucesso.')
  })

  it('creates a professional without any services', async () => {
    const user = userEvent.setup()
    listProfessionals.mockResolvedValue({ data: [] })
    listServices.mockResolvedValue({ data: [activeService] })
    createProfessional.mockResolvedValue({ data: { ...professional, services: [] } })

    renderProfessionals()

    await screen.findByText('Nenhum profissional cadastrado ainda.')
    await user.type(screen.getByLabelText('Nome'), 'Sem Serviços')
    await user.click(screen.getByRole('button', { name: 'Criar' }))

    await waitFor(() =>
      expect(createProfessional).toHaveBeenCalledWith(expect.objectContaining({ service_ids: [] }))
    )
  })

  it('shows field validation errors and preserves typed values', async () => {
    const user = userEvent.setup()
    listProfessionals.mockResolvedValue({ data: [] })
    listServices.mockResolvedValue({ data: [] })
    createProfessional.mockRejectedValue(
      new ApiError(422, 'VALIDATION_ERROR', 'Dados inválidos.', {
        name: ['The name field is required.'],
      })
    )

    renderProfessionals()

    await screen.findByText('Nenhum profissional cadastrado ainda.')
    await user.type(screen.getByLabelText('Nome'), 'X')
    await user.click(screen.getByRole('button', { name: 'Criar' }))

    expect(await screen.findByText('The name field is required.')).toBeInTheDocument()
    expect(screen.getByLabelText('Nome')).toHaveValue('X')
  })

  it('edits an existing professional, prefilling the form with current services checked', async () => {
    const user = userEvent.setup()
    listProfessionals.mockResolvedValue({ data: [professional] })
    listServices.mockResolvedValue({ data: [activeService, inactiveService] })
    updateProfessional.mockResolvedValue({ data: professional })

    renderProfessionals()

    await screen.findByText('Lucas')
    await user.click(screen.getByRole('button', { name: 'Editar' }))

    expect(screen.getByRole('checkbox', { name: /Corte/ })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: /Sobrancelha/ })).not.toBeChecked()

    await user.click(screen.getByRole('checkbox', { name: /Sobrancelha/ }))
    await user.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(updateProfessional).toHaveBeenCalledWith(
        1,
        expect.objectContaining({ service_ids: expect.arrayContaining([activeService.id, inactiveService.id]) })
      )
    )
  })

  it('can deactivate a professional', async () => {
    const user = userEvent.setup()
    listProfessionals.mockResolvedValue({ data: [professional] })
    listServices.mockResolvedValue({ data: [activeService] })
    updateProfessional.mockResolvedValue({ data: { ...professional, is_active: false } })

    renderProfessionals()

    await screen.findByText('Lucas')
    await user.click(screen.getByRole('button', { name: 'Editar' }))
    await user.click(screen.getByLabelText('Ativo'))
    await user.click(screen.getByRole('button', { name: 'Salvar' }))

    await waitFor(() =>
      expect(updateProfessional).toHaveBeenCalledWith(1, expect.objectContaining({ is_active: false }))
    )
  })
})
