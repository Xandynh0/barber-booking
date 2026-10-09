import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { ApiError } from './api/client'

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
vi.mock('./api/workingHours')
vi.mock('./api/scheduleBlocks')
vi.mock('./api/businessSettings')
import { me } from './api/auth'
import { listServices } from './api/services'
import { listProfessionals } from './api/professionals'
import { getWorkingHours } from './api/workingHours'
import { listScheduleBlocks } from './api/scheduleBlocks'
import { getBusinessSettings } from './api/businessSettings'

function renderAppAt(path) {
  window.history.pushState({}, '', path)
  return render(<App />)
}

describe('App routing (admin area)', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })
    listProfessionals.mockResolvedValue({ data: [] })
    getWorkingHours.mockResolvedValue({
      data: { professional_id: 1, days: Array.from({ length: 7 }, (_, weekday) => ({ weekday, periods: [] })) },
    })
    listScheduleBlocks.mockResolvedValue({ data: [] })
    getBusinessSettings.mockResolvedValue({ data: { timezone: 'America/Sao_Paulo' } })
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

  it('renders the admin navigation and the working hours heading at /admin/expediente', async () => {
    renderAppAt('/admin/expediente')

    expect(await screen.findByRole('navigation', { name: 'Navegação administrativa' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Bloqueios' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Expediente semanal' })).toBeInTheDocument()
    await waitFor(() =>
      expect(
        screen.getByText('Nenhum profissional cadastrado ainda — cadastre em "Profissionais" primeiro.')
      ).toBeInTheDocument()
    )
  })

  it('renders the admin navigation and the schedule blocks heading at /admin/bloqueios', async () => {
    renderAppAt('/admin/bloqueios')

    expect(await screen.findByRole('navigation', { name: 'Navegação administrativa' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Expediente' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Bloqueios cadastrados' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Nenhum bloqueio cadastrado ainda.')).toBeInTheDocument())
  })
})

describe('Language switching (App/router real)', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin', email: 'admin@barberbooking.test' } })
    listServices.mockResolvedValue({ data: [] })
    listProfessionals.mockResolvedValue({ data: [] })
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('switches the public homepage to English without a page reload', async () => {
    const user = userEvent.setup()
    renderAppAt('/')

    expect(await screen.findByText('Tradição no estilo. Simplicidade na agenda.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByText('Tradition in style. Simplicity in scheduling.')).toBeInTheDocument()
    expect(document.documentElement.lang).toBe('en')
  })

  it('switches the login screen to English while preserving typed form values', async () => {
    const user = userEvent.setup()
    me.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    renderAppAt('/admin/login')

    await screen.findByLabelText('E-mail')
    await user.type(screen.getByLabelText('E-mail'), 'admin@barberbooking.test')

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByLabelText('Email')).toHaveValue('admin@barberbooking.test')
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('switches the admin nav to English without losing the authenticated session', async () => {
    const user = userEvent.setup()
    renderAppAt('/admin/agenda')

    expect(await screen.findByRole('link', { name: 'Serviços' })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByRole('link', { name: 'Services' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Professionals' })).toBeInTheDocument()
    // Still on the agenda screen, still authenticated — switching language
    // never redirects to login or re-triggers the session check.
    expect(screen.getByText('The schedule will be implemented in a future stage.')).toBeInTheDocument()
    expect(me).toHaveBeenCalledTimes(1)
  })
})
