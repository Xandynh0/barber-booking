import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'

// The real App, router, API client and CSRF flow: only `fetch` is replaced,
// by a small fake server. Slots are generated from the requested date, so
// the tests do not depend on today's date.

const business = { name: 'Barbearia Teste', address: 'Rua das Navalhas, 10', phone: null, timezone: 'America/Sao_Paulo', booking_horizon_days: 30 }
const services = [
  { id: 1, name: 'Corte degradê', description: 'Com acabamento.', duration_minutes: 45, price: '55.90' },
  { id: 2, name: 'Barba tradicional', description: null, duration_minutes: 30, price: '35.50' },
]
const professionalsByService = {
  1: [{ id: 10, name: 'Rafael Almeida', description: 'Degradê.' }],
  2: [{ id: 20, name: 'Bruno Costa', description: null }],
}

function slotsFor(date, utcTimes = ['13:00', '13:45']) {
  return utcTimes.map((time) => {
    const start = new Date(`${date}T${time}:00Z`)
    return { starts_at: start.toISOString().replace('.000Z', '+00:00'), ends_at: new Date(start.getTime() + 45 * 60000).toISOString().replace('.000Z', '+00:00') }
  })
}

function json(status, body) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body })
}

function createdBody(payload, overrides = {}) {
  return {
    data: {
      public_id: '01k9testpublicid000000000a',
      status: 'confirmed',
      starts_at: payload.starts_at,
      ends_at: new Date(new Date(payload.starts_at).getTime() + 45 * 60000).toISOString().replace('.000Z', '+00:00'),
      timezone: 'America/Sao_Paulo',
      service: { id: 1, name: 'Corte degradê', duration_minutes: 45, price: '55.90' },
      professional: { id: 10, name: 'Rafael Almeida' },
      customer_name: payload.customer_name,
      notification_status: 'sent',
      ...overrides,
    },
  }
}

let server
let posts

function defaultServer() {
  return {
    availability: (date) => json(200, { data: { timezone: 'America/Sao_Paulo', date, slots: slotsFor(date) } }),
    createAppointment: (payload) => json(201, createdBody(payload)),
  }
}

function fakeFetch(url, options = {}) {
  const method = (options.method ?? 'GET').toUpperCase()
  const path = String(url)

  if (path === '/sanctum/csrf-cookie') return Promise.resolve({ ok: true, status: 204, json: async () => null })
  if (path === '/api/v1/public/business') return json(200, { data: business })
  if (path === '/api/v1/public/services') return json(200, { data: services })

  const professionals = path.match(/^\/api\/v1\/public\/services\/(\d+)\/professionals$/)
  if (professionals) return json(200, { data: professionalsByService[professionals[1]] })

  if (path.startsWith('/api/v1/public/availability?')) {
    return server.availability(new URLSearchParams(path.split('?')[1]).get('date'))
  }

  if (path === '/api/v1/public/appointments' && method === 'POST') {
    const payload = JSON.parse(options.body)
    posts.push({ payload, key: options.headers['Idempotency-Key'] })
    return server.createAppointment(payload, posts.length)
  }

  return json(404, { error: { code: 'NOT_FOUND', message: 'Not found' } })
}

function renderBooking(path = '/agendar') {
  window.history.pushState({}, '', path)
  return render(<App />)
}

async function chooseServiceAndProfessional(user, serviceName = 'Corte degradê', professionalName = 'Rafael Almeida') {
  await user.click(await screen.findByRole('button', { name: new RegExp(serviceName) }))
  await user.click(screen.getByRole('button', { name: 'Continuar' }))
  await user.click(await screen.findByRole('button', { name: new RegExp(professionalName) }))
  await user.click(screen.getByRole('button', { name: 'Continuar' }))
}

async function fillCustomer(user, values = {}) {
  const { name = 'Cliente Teste', email = 'cliente@example.com', phone = '(11) 99999-0000' } = values
  await user.type(await screen.findByLabelText('Nome'), name)
  await user.type(screen.getByLabelText('E-mail'), email)
  await user.type(screen.getByLabelText('Telefone com DDD'), phone)
}

async function goToReview(user) {
  await chooseServiceAndProfessional(user)
  await user.click(await screen.findByRole('button', { name: '10:00' }))
  await user.click(screen.getByRole('button', { name: 'Continuar' }))
  await fillCustomer(user)
  await user.click(screen.getByRole('button', { name: 'Continuar' }))
  await screen.findByRole('heading', { name: 'Revise e confirme' })
}

function summaryValue(label) {
  const summary = screen.getByRole('complementary')
  const term = within(summary).getByText(label, { selector: 'dt' })
  return term.nextElementSibling.textContent
}

describe('Public booking journey', () => {
  beforeEach(() => {
    server = defaultServer()
    posts = []
    vi.stubGlobal('fetch', vi.fn(fakeFetch))
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    localStorage.removeItem('barber-booking-language')
  })

  it('books from service to confirmation, showing times in the barbershop timezone', async () => {
    const user = userEvent.setup()
    renderBooking()

    await chooseServiceAndProfessional(user)

    // 13:00 UTC is 10:00 in São Paulo — the browser timezone never matters.
    await user.click(await screen.findByRole('button', { name: '10:00' }))
    expect(summaryValue('Horário')).toBe('10:00')
    await user.click(screen.getByRole('button', { name: 'Continuar' }))

    await fillCustomer(user)
    await user.click(screen.getByRole('button', { name: 'Continuar' }))

    expect(await screen.findByRole('heading', { name: 'Revise e confirme' })).toHaveFocus()
    expect(screen.getByText('cliente@example.com')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('heading', { name: 'Reserva confirmada' })).toHaveFocus()
    expect(screen.getByText('01k9testpublicid000000000a')).toBeInTheDocument()
    expect(screen.getByText('10:00–10:45')).toBeInTheDocument()
    expect(screen.getByText(/Enviamos a confirmação, com o link para cancelar, para cliente@example.com/)).toBeInTheDocument()

    expect(posts).toHaveLength(1)
    expect(posts[0].payload).toEqual({
      service_id: 1,
      professional_id: 10,
      starts_at: expect.stringMatching(/T13:00:00\+00:00$/),
      customer_name: 'Cliente Teste',
      customer_email: 'cliente@example.com',
      customer_phone: '(11) 99999-0000',
    })
    expect(posts[0].key).toMatch(/^[0-9a-f-]{36}$/)

    const calls = fetch.mock.calls.map(([url]) => String(url))
    expect(calls.lastIndexOf('/sanctum/csrf-cookie')).toBeLessThan(calls.lastIndexOf('/api/v1/public/appointments'))
  })

  it('invalidates the professional and the time when the service changes, and the time when the day changes', async () => {
    const user = userEvent.setup()
    let releaseSecondDay = null
    let calls = 0
    server.availability = (date) => {
      calls++
      if (calls === 2) {
        // Hold the new day's answer: the old time must already be gone
        // while it loads, not only once the new slots arrive.
        return new Promise((resolve) => {
          releaseSecondDay = () => resolve({ ok: true, status: 200, json: async () => ({ data: { date, slots: slotsFor(date) } }) })
        })
      }
      return json(200, { data: { date, slots: slotsFor(date) } })
    }
    renderBooking()

    await chooseServiceAndProfessional(user)
    await user.click(await screen.findByRole('button', { name: '10:00' }))
    expect(summaryValue('Horário')).toBe('10:00')

    // Another day: the chosen time no longer applies — immediately.
    const dayButtons = within(screen.getByRole('list', { name: 'Dia' })).getAllByRole('button')
    await user.click(dayButtons[1])
    expect(screen.getByText('Carregando horários…')).toBeInTheDocument()
    expect(summaryValue('Horário')).toBe('A escolher')
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeDisabled()
    await act(async () => releaseSecondDay())

    await user.click(await screen.findByRole('button', { name: '10:00' }))
    await user.click(screen.getByRole('button', { name: 'Voltar' }))
    await user.click(screen.getByRole('button', { name: 'Voltar' }))

    // Another service: its professionals and its times are different.
    await user.click(screen.getByRole('button', { name: /Barba tradicional/ }))
    expect(summaryValue('Profissional')).toBe('A escolher')
    expect(summaryValue('Horário')).toBe('A escolher')
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    expect(await screen.findByRole('button', { name: /Bruno Costa/ })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Rafael Almeida/ })).not.toBeInTheDocument()
  })

  it('ignores an availability answer that arrives after a newer query', async () => {
    const user = userEvent.setup()
    let releaseFirstDay
    let firstDay = null
    server.availability = (date) => {
      if (firstDay === null) {
        firstDay = date
        // The first day's slow answer would show 08:00 (11:00 UTC).
        return new Promise((resolve) => {
          releaseFirstDay = () => resolve({ ok: true, status: 200, json: async () => ({ data: { date, slots: slotsFor(date, ['11:00']) } }) })
        })
      }
      return json(200, { data: { date, slots: slotsFor(date, ['13:00']) } })
    }

    renderBooking()
    await chooseServiceAndProfessional(user)
    await screen.findByText('Carregando horários…')

    const dayButtons = within(screen.getByRole('list', { name: 'Dia' })).getAllByRole('button')
    await user.click(dayButtons[1])
    expect(await screen.findByRole('button', { name: '10:00' })).toBeInTheDocument()

    await act(async () => releaseFirstDay())

    expect(screen.getByRole('button', { name: '10:00' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '08:00' })).not.toBeInTheDocument()
  })

  it('explains a slot taken meanwhile, refreshes the times and keeps the customer details', async () => {
    const user = userEvent.setup()
    let availabilityCalls = 0
    server.availability = (date) => {
      availabilityCalls++
      // After the conflict, 10:00 is gone.
      return json(200, { data: { date, slots: availabilityCalls === 1 ? slotsFor(date) : slotsFor(date, ['13:45']) } })
    }
    server.createAppointment = (payload, attempt) =>
      attempt === 1
        ? json(409, { error: { code: 'SLOT_UNAVAILABLE', message: 'Esse horário não está mais disponível.' } })
        : json(201, createdBody(payload))

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Esse horário acabou de ser reservado ou não está mais disponível.')
    expect(screen.getByRole('heading', { name: 'Escolha o dia e o horário' })).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: '10:45' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: '10:00' })).not.toBeInTheDocument()
    expect(availabilityCalls).toBe(2)

    await user.click(screen.getByRole('button', { name: '10:45' }))
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    expect(screen.getByLabelText('Nome')).toHaveValue('Cliente Teste')
    expect(screen.getByLabelText('E-mail')).toHaveValue('cliente@example.com')
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    await user.click(await screen.findByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('heading', { name: 'Reserva confirmada' })).toBeInTheDocument()
    expect(posts).toHaveLength(2)
    expect(posts[1].key).not.toBe(posts[0].key)
  })

  it('retries an uncertain attempt with the same idempotency key', async () => {
    const user = userEvent.setup()
    server.createAppointment = (payload, attempt) =>
      attempt === 1 ? Promise.reject(new TypeError('Failed to fetch')) : json(200, createdBody(payload))

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Não conseguimos confirmar se a reserva foi registrada.')
    await user.click(screen.getByRole('button', { name: 'Tentar confirmar novamente' }))

    expect(await screen.findByRole('heading', { name: 'Reserva confirmada' })).toBeInTheDocument()
    expect(posts).toHaveLength(2)
    expect(posts[1].key).toBe(posts[0].key)
    expect(posts[1].payload).toEqual(posts[0].payload)
  })

  it('uses a new idempotency key when the payload changes after a failed attempt', async () => {
    const user = userEvent.setup()
    server.createAppointment = (payload, attempt) =>
      attempt === 1 ? json(503, { error: { code: 'SERVICE_UNAVAILABLE', message: 'Indisponível' } }) : json(201, createdBody(payload))

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))
    await screen.findByRole('alert')

    await user.click(screen.getByRole('button', { name: 'Voltar' }))
    const name = screen.getByLabelText('Nome')
    await user.clear(name)
    await user.type(name, 'Outro Nome')
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    await user.click(await screen.findByRole('button', { name: 'Confirmar agendamento' }))

    await screen.findByRole('heading', { name: 'Reserva confirmada' })
    expect(posts[1].key).not.toBe(posts[0].key)
  })

  it('sends only one request when confirm is clicked repeatedly', async () => {
    const user = userEvent.setup()
    let respond
    server.createAppointment = (payload) =>
      new Promise((resolve) => {
        respond = () => resolve({ ok: true, status: 201, json: async () => createdBody(payload) })
      })

    renderBooking()
    await goToReview(user)
    const confirm = screen.getByRole('button', { name: 'Confirmar agendamento' })
    await user.click(confirm)
    await user.click(screen.getByRole('button', { name: 'Confirmando…' }))
    await user.dblClick(screen.getByRole('button', { name: 'Confirmando…' }))

    expect(screen.getByRole('button', { name: 'Confirmando…' })).toBeDisabled()
    await act(async () => respond())
    await screen.findByRole('heading', { name: 'Reserva confirmada' })
    expect(posts).toHaveLength(1)
  })

  it('shows field errors from the API on the details step, keeping what was typed', async () => {
    const user = userEvent.setup()
    server.createAppointment = () =>
      json(422, { error: { code: 'VALIDATION_ERROR', message: 'Dados inválidos.', fields: { customer_email: ['O campo e-mail deve ser um e-mail válido.'] } } })

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('heading', { name: 'Seus dados' })).toBeInTheDocument()
    expect(screen.getByText('O campo e-mail deve ser um e-mail válido.')).toBeInTheDocument()
    expect(screen.getByLabelText('E-mail')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('E-mail')).toHaveValue('cliente@example.com')
    expect(screen.getByLabelText('Nome')).toHaveValue('Cliente Teste')
  })

  it('shows the contact limit without leaving the review', async () => {
    const user = userEvent.setup()
    server.createAppointment = () => json(409, { error: { code: 'CONTACT_LIMIT_REACHED', message: 'Limite.' } })

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Há um limite de reservas futuras por contato.')
    expect(screen.getByRole('heading', { name: 'Revise e confirme' })).toBeInTheDocument()
  })

  it('treats a failed e-mail as a confirmed booking, without promising the e-mail arrived', async () => {
    const user = userEvent.setup()
    server.createAppointment = (payload) => json(201, createdBody(payload, { notification_status: 'failed' }))

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))

    expect(await screen.findByRole('heading', { name: 'Reserva confirmada' })).toBeInTheDocument()
    expect(screen.getByText(/ainda não pôde ser enviado para cliente@example.com/)).toBeInTheDocument()
    expect(screen.queryByText(/Enviamos a confirmação/)).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('switches language mid-journey keeping every choice and typed value', async () => {
    const user = userEvent.setup()
    renderBooking()

    await chooseServiceAndProfessional(user)
    await user.click(await screen.findByRole('button', { name: '10:00' }))
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    await fillCustomer(user)

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByRole('heading', { name: 'Your details' })).toBeInTheDocument()
    expect(screen.getByLabelText('Name')).toHaveValue('Cliente Teste')
    expect(screen.getByLabelText('Email')).toHaveValue('cliente@example.com')
    expect(summaryValue('Professional')).toBe('Rafael Almeida')
    expect(summaryValue('Time')).toBe('10:00')
    expect(summaryValue('Price')).toBe('R$55.90')

    await user.click(screen.getByRole('button', { name: 'Back' }))
    expect(screen.getByRole('button', { name: '10:00' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('keeps English when going from the homepage to the booking journey', async () => {
    const user = userEvent.setup()
    renderBooking('/')

    await user.click(await screen.findByRole('button', { name: 'English' }))
    expect(await screen.findByRole('link', { name: 'Book an appointment' })).toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Book an appointment' }))

    expect(window.location.pathname).toBe('/agendar')
    expect(await screen.findByRole('heading', { name: 'Choose the service' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'English' })).toHaveAttribute('aria-pressed', 'true')
    // What a full page load of /agendar reads back (i18n/index.js).
    expect(localStorage.getItem('barber-booking-language')).toBe('en')
  })

  it('never writes personal data to localStorage', async () => {
    const user = userEvent.setup()
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    renderBooking()
    await goToReview(user)
    await user.click(screen.getByRole('button', { name: 'Confirmar agendamento' }))
    await screen.findByRole('heading', { name: 'Reserva confirmada' })

    const written = setItem.mock.calls.map(([key, value]) => `${key}=${value}`).join('\n')
    expect(written).not.toMatch(/Cliente Teste|cliente@example\.com|99999/)
  })

  it('preselects a service coming from the homepage', async () => {
    const user = userEvent.setup()
    renderBooking('/agendar?servico=2')

    expect(await screen.findByRole('button', { name: /Barba tradicional/ })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: 'Continuar' }))
    expect(await screen.findByRole('button', { name: /Bruno Costa/ })).toBeInTheDocument()
  })

  it('drops a preselected service that is no longer bookable', async () => {
    renderBooking('/agendar?servico=99')

    expect(await screen.findByRole('button', { name: /Corte degradê/ })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('button', { name: /Barba tradicional/ })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeDisabled()
  })

  it('offers a retry when the times fail to load, going back to loading meanwhile', async () => {
    const user = userEvent.setup()
    let release = null
    let calls = 0
    server.availability = (date) => {
      calls++
      if (calls === 1) return Promise.reject(new TypeError('Failed to fetch'))
      return new Promise((resolve) => {
        release = () => resolve({ ok: true, status: 200, json: async () => ({ data: { date, slots: slotsFor(date) } }) })
      })
    }

    renderBooking()
    await chooseServiceAndProfessional(user)
    expect(await screen.findByText('Não foi possível carregar os horários.')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }))
    expect(screen.getByText('Carregando horários…')).toBeInTheDocument()
    expect(screen.queryByText('Não foi possível carregar os horários.')).not.toBeInTheDocument()
    await act(async () => release())
    expect(await screen.findByRole('button', { name: '10:00' })).toBeInTheDocument()
  })

  it('offers a retry when the catalog fails to load', async () => {
    const user = userEvent.setup()
    let failing = true
    fetch.mockImplementation((url, options) => {
      if (failing && String(url) === '/api/v1/public/services') return Promise.reject(new TypeError('Failed to fetch'))
      return fakeFetch(url, options)
    })

    renderBooking()
    expect(await screen.findByText('Não foi possível carregar os serviços.')).toBeInTheDocument()

    failing = false
    await user.click(screen.getByRole('button', { name: 'Tentar novamente' }))
    await waitFor(() => expect(screen.getByRole('button', { name: /Corte degradê/ })).toBeInTheDocument())
  })
})
