import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Home from './Home'

function json(status, body) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body })
}

const business = { name: 'Barbearia Teste', address: 'Rua das Navalhas, 10', phone: '(11) 3333-4444', timezone: 'America/Sao_Paulo', booking_horizon_days: 30 }
const services = [{ id: 7, name: 'Corte clássico', description: 'Tesoura e acabamento.', duration_minutes: 30, price: '40.00' }]

function renderHome() {
  return render(
    <MemoryRouter>
      <Home />
    </MemoryRouter>
  )
}

describe('Home', () => {
  let servicesResponse

  beforeEach(() => {
    servicesResponse = () => json(200, { data: services })
    vi.stubGlobal(
      'fetch',
      vi.fn((url) => {
        if (url === '/api/v1/public/business') return json(200, { data: business })
        if (url === '/api/v1/public/services') return servicesResponse()
        return json(404, {})
      })
    )
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the barbershop and the bookable services from the API, with links into the booking journey', async () => {
    renderHome()

    expect(await screen.findByRole('heading', { level: 1, name: 'Barbearia Teste' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Agendar horário' })).toHaveAttribute('href', '/agendar')

    const item = (await screen.findByText('Corte clássico')).closest('li')
    expect(within(item).getByText('Tesoura e acabamento.')).toBeInTheDocument()
    expect(within(item).getByText('30 min')).toBeInTheDocument()
    expect(within(item).getByText('R$ 40,00')).toBeInTheDocument()
    expect(within(item).getByRole('link', { name: /Agendar este serviço/ })).toHaveAttribute('href', '/agendar?servico=7')

    expect(screen.getByText('Rua das Navalhas, 10')).toBeInTheDocument()
    expect(screen.getByText('(11) 3333-4444')).toBeInTheDocument()
  })

  it('says so when nothing can be booked', async () => {
    servicesResponse = () => json(200, { data: [] })

    renderHome()

    expect(await screen.findByText('Nenhum serviço disponível para agendamento no momento.')).toBeInTheDocument()
  })

  it('offers a retry when the services fail to load', async () => {
    const user = userEvent.setup()
    servicesResponse = () => Promise.reject(new TypeError('Failed to fetch'))

    renderHome()

    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível carregar os serviços.')
    servicesResponse = () => json(200, { data: services })
    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }))

    expect(await screen.findByText('Corte clássico')).toBeInTheDocument()
  })
})
