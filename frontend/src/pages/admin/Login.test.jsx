import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import Login from './Login'

vi.mock('../../api/auth')
import { login, me } from '../../api/auth'

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/admin/login']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<Login />} />
          <Route path="/admin/agenda" element={<p>Tela da agenda</p>} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

describe('Login', () => {
  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows a checking state, then the form once the session check resolves to guest', async () => {
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))

    renderLogin()

    expect(screen.getByText('Verificando sessão...')).toBeInTheDocument()

    await waitFor(() => expect(screen.getByLabelText('E-mail')).toBeInTheDocument())
    expect(screen.getByLabelText('Senha')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Entrar' })).toBeInTheDocument()
  })

  it('logs in and redirects to /admin/agenda on success', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    login.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })

    renderLogin()

    await screen.findByLabelText('E-mail')
    await user.type(screen.getByLabelText('E-mail'), 'admin@barberbooking.test')
    await user.type(screen.getByLabelText('Senha'), 'correct-horse-battery-staple')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    await waitFor(() => expect(screen.getByText('Tela da agenda')).toBeInTheDocument())
    expect(login).toHaveBeenCalledWith({
      email: 'admin@barberbooking.test',
      password: 'correct-horse-battery-staple',
    })
  })

  it('shows a generic message for invalid credentials', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    login.mockRejectedValue(new ApiError(401, 'INVALID_CREDENTIALS', 'E-mail ou senha inválidos.'))

    renderLogin()

    await screen.findByLabelText('E-mail')
    await user.type(screen.getByLabelText('E-mail'), 'admin@barberbooking.test')
    await user.type(screen.getByLabelText('Senha'), 'wrong')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha inválidos.')
  })

  it('shows field-level validation errors', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    login.mockRejectedValue(
      new ApiError(422, 'VALIDATION_ERROR', 'Dados inválidos.', {
        email: ['The email field is required.'],
        password: ['The password field is required.'],
      })
    )

    renderLogin()

    await screen.findByLabelText('E-mail')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByText('The email field is required.')).toBeInTheDocument()
    expect(screen.getByText('The password field is required.')).toBeInTheDocument()
  })

  it('shows a rate-limit message with no automatic retry', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    login.mockRejectedValue(new ApiError(429, 'RATE_LIMITED', 'Muitas tentativas.'))

    renderLogin()

    await screen.findByLabelText('E-mail')
    await user.type(screen.getByLabelText('E-mail'), 'admin@barberbooking.test')
    await user.type(screen.getByLabelText('Senha'), 'whatever')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/muitas tentativas/i)
    expect(login).toHaveBeenCalledTimes(1)
  })

  it('shows a connection failure message when the network request itself fails', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    login.mockRejectedValue(new ApiError(0, 'NETWORK_ERROR', 'Não foi possível conectar ao servidor.'))

    renderLogin()

    await screen.findByLabelText('E-mail')
    await user.type(screen.getByLabelText('E-mail'), 'admin@barberbooking.test')
    await user.type(screen.getByLabelText('Senha'), 'whatever')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/não foi possível conectar/i)
  })

  it('redirects straight to /admin/agenda when the session check reports an authenticated admin', async () => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })

    renderLogin()

    await waitFor(() => expect(screen.getByText('Tela da agenda')).toBeInTheDocument())
  })
})
