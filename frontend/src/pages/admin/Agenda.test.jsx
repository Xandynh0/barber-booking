import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../../context/AuthContext'
import Agenda from './Agenda'

vi.mock('../../api/auth')
import { logout, me } from '../../api/auth'

function renderAgenda() {
  return render(
    <MemoryRouter initialEntries={['/admin/agenda']}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route path="/admin/agenda" element={<Agenda />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

describe('Agenda', () => {
  afterEach(() => {
    vi.resetAllMocks()
  })

  it("shows the admin's identity, a Sair button, and the coming-soon message — no fake bookings", async () => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin Dev', email: 'admin@barberbooking.test' } })

    renderAgenda()

    await waitFor(() =>
      expect(screen.getByText(/Admin Dev.*admin@barberbooking\.test/)).toBeInTheDocument()
    )
    expect(screen.getByRole('button', { name: 'Sair' })).toBeInTheDocument()
    expect(screen.getByText('A agenda será implementada em uma próxima etapa.')).toBeInTheDocument()

    // Explicitly nothing resembling a booking list/table is rendered.
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByRole('list')).not.toBeInTheDocument()
  })

  it('logout clears the session and returns to the login screen', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin Dev', email: 'admin@barberbooking.test' } })
    logout.mockResolvedValue(null)

    renderAgenda()

    const signOutButton = await screen.findByRole('button', { name: 'Sair' })
    await user.click(signOutButton)

    await waitFor(() => expect(screen.getByText('Tela de login')).toBeInTheDocument())
    expect(logout).toHaveBeenCalledTimes(1)
  })

  it('still navigates to login if the logout request itself fails (no retry loop)', async () => {
    const user = userEvent.setup()
    me.mockResolvedValue({ data: { id: 1, name: 'Admin Dev', email: 'admin@barberbooking.test' } })
    logout.mockRejectedValue(new Error('network down'))

    renderAgenda()

    const signOutButton = await screen.findByRole('button', { name: 'Sair' })
    await user.click(signOutButton)

    await waitFor(() => expect(screen.getByText('Tela de login')).toBeInTheDocument())
    expect(logout).toHaveBeenCalledTimes(1)
  })
})
