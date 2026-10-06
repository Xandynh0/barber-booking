import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../api/client'
import { AuthProvider } from '../context/AuthContext'
import { ProtectedRoute } from './ProtectedRoute'

vi.mock('../api/auth')
import { me } from '../api/auth'

function renderProtected() {
  return render(
    <MemoryRouter initialEntries={['/admin/agenda']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route
            path="/admin/agenda"
            element={
              <ProtectedRoute>
                <p>Conteúdo protegido</p>
              </ProtectedRoute>
            }
          />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

describe('ProtectedRoute', () => {
  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows a loading state while the session check is pending', () => {
    me.mockReturnValue(new Promise(() => {}))

    renderProtected()

    expect(screen.getByText('Verificando sessão...')).toBeInTheDocument()
  })

  it('renders the protected content once the session check confirms an authenticated admin', async () => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })

    renderProtected()

    await waitFor(() => expect(screen.getByText('Conteúdo protegido')).toBeInTheDocument())
  })

  it('redirects to /admin/login when there is no session', async () => {
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))

    renderProtected()

    await waitFor(() => expect(screen.getByText('Tela de login')).toBeInTheDocument())
    expect(screen.queryByText('Conteúdo protegido')).not.toBeInTheDocument()
  })

  it('redirects to /admin/login when the session check reports an expired session (419)', async () => {
    me.mockRejectedValue(new ApiError(419, 'SESSION_EXPIRED', 'Sessão expirada.'))

    renderProtected()

    await waitFor(() => expect(screen.getByText('Tela de login')).toBeInTheDocument())
  })
})
