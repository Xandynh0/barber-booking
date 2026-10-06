import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'

// This exercises the real App tree (real BrowserRouter, real nested
// AdminArea <Routes>, real AdminLayout/AdminNav), unlike the per-page tests
// which render each page in isolation with its own MemoryRouter. A route
// that silently fails to match (e.g. because the route list a page expects
// to exist diverges from what's actually registered in App.jsx) renders
// nothing and no page-level test can catch that — only asserting on the
// fully assembled router here does.
vi.mock('./api/auth')
vi.mock('./api/services')
vi.mock('./api/professionals')
import { me } from './api/auth'
import { listServices } from './api/services'
import { listProfessionals } from './api/professionals'

function renderAppAt(path) {
  window.history.pushState({}, '', path)
  return render(<App />)
}

describe('App routing (admin area)', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })
    listProfessionals.mockResolvedValue({ data: [] })
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('renders the admin navigation and the agenda placeholder at /admin/agenda', async () => {
    renderAppAt('/admin/agenda')

    expect(await screen.findByRole('navigation', { name: 'Navegação administrativa' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Serviços' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Profissionais' })).toBeInTheDocument()
    expect(screen.getByText('A agenda será implementada em uma próxima etapa.')).toBeInTheDocument()
  })

  it('renders the admin navigation and the services form at /admin/servicos', async () => {
    renderAppAt('/admin/servicos')

    expect(await screen.findByRole('navigation', { name: 'Navegação administrativa' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Agenda' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Novo serviço' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Nenhum serviço cadastrado ainda.')).toBeInTheDocument())
  })

  it('renders the admin navigation and the professionals form at /admin/profissionais', async () => {
    renderAppAt('/admin/profissionais')

    expect(await screen.findByRole('navigation', { name: 'Navegação administrativa' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Agenda' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Novo profissional' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Nenhum profissional cadastrado ainda.')).toBeInTheDocument())
  })
})
