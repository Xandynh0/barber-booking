import { act, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../api/client'
import { AuthProvider } from '../../context/AuthContext'
import i18n from '../../i18n'
import Agenda from './Agenda'

vi.mock('../../api/auth')
vi.mock('../../api/agenda')
vi.mock('../../api/businessSettings')
vi.mock('../../api/services')
vi.mock('../../api/professionals')
import { logout, me } from '../../api/auth'
import { createAdminAppointment, getAdminAvailability, getAgenda, getAppointment, runAppointmentAction } from '../../api/agenda'
import { getBusinessSettings } from '../../api/businessSettings'
import { listServices } from '../../api/services'
import { listProfessionals } from '../../api/professionals'

const TZ = 'America/Sao_Paulo'
const DAY = '2026-11-02'

const services = [
  { id: 1, name: 'Corte degradê', duration_minutes: 45, price: '55.90', is_active: true },
  { id: 2, name: 'Barba', duration_minutes: 30, price: '35.00', is_active: false },
]
const professionals = [
  { id: 7, name: 'Rafael Almeida', is_active: true, services: [{ id: 1, name: 'Corte degradê', is_active: true }] },
  { id: 8, name: 'Bruno Costa', is_active: true, services: [] },
]

function summary(overrides = {}) {
  return {
    public_id: '01AAA',
    professional_id: 7,
    status: 'confirmed',
    source: 'public',
    starts_at: '2026-11-02T13:00:00+00:00',
    ends_at: '2026-11-02T13:45:00+00:00',
    visible_starts_at: '2026-11-02T13:00:00+00:00',
    visible_ends_at: '2026-11-02T13:45:00+00:00',
    continues_before: false,
    continues_after: false,
    service: { id: 1, name: 'Corte degradê', duration_minutes: 45 },
    customer_name: 'João Silva',
    has_email: true,
    notification_status: 'sent',
    allowed_actions: ['cancel', 'resend_confirmation'],
    ...overrides,
  }
}

function agendaFor(date = DAY, overrides = {}) {
  return {
    data: {
      date,
      timezone: TZ,
      now: '2026-11-02T11:00:00+00:00',
      day: { starts_at: `${date}T03:00:00+00:00`, ends_at: '2026-11-03T03:00:00+00:00' },
      hours: { starts_at: `${date}T12:00:00+00:00`, ends_at: `${date}T21:00:00+00:00` },
      professionals: [
        {
          id: 7,
          name: 'Rafael Almeida',
          is_active: true,
          working_periods: [{ starts_at: `${date}T12:00:00+00:00`, ends_at: `${date}T21:00:00+00:00` }],
          breaks: [],
        },
      ],
      appointments: [
        summary(),
        summary({
          public_id: '01BBB',
          customer_name: 'Pedro Alves',
          status: 'cancelled',
          starts_at: '2026-11-02T14:00:00+00:00',
          ends_at: '2026-11-02T14:45:00+00:00',
          visible_starts_at: '2026-11-02T14:00:00+00:00',
          visible_ends_at: '2026-11-02T14:45:00+00:00',
          allowed_actions: [],
        }),
        summary({
          public_id: '01CCC',
          customer_name: 'Carlos Souza',
          notification_status: 'failed',
          starts_at: '2026-11-02T16:00:00+00:00',
          ends_at: '2026-11-02T16:45:00+00:00',
          visible_starts_at: '2026-11-02T16:00:00+00:00',
          visible_ends_at: '2026-11-02T16:45:00+00:00',
        }),
      ],
      blocks: [
        {
          id: 3,
          kind: 'shop',
          group_id: '01GGG',
          professional_id: 7,
          reason: 'Reforma',
          starts_at: '2026-11-02T19:00:00+00:00',
          ends_at: '2026-11-02T21:00:00+00:00',
          visible_starts_at: '2026-11-02T19:00:00+00:00',
          visible_ends_at: '2026-11-02T21:00:00+00:00',
          continues_before: false,
          continues_after: false,
        },
      ],
      ...overrides,
    },
  }
}

function details(overrides = {}) {
  return {
    data: {
      public_id: '01AAA',
      status: 'confirmed',
      source: 'public',
      starts_at: '2026-11-02T13:00:00+00:00',
      ends_at: '2026-11-02T13:45:00+00:00',
      timezone: TZ,
      service: { id: 1, name: 'Corte degradê', duration_minutes: 45, price: '55.90' },
      professional: { id: 7, name: 'Rafael Almeida' },
      customer: { name: 'João Silva', email: 'joao@example.com', phone: '+5511999990000' },
      notification: { status: 'sent', attempts: 1, max_attempts: 5, sent_at: '2026-11-01T12:00:00+00:00' },
      notification_status: 'sent',
      cancelled_at: null,
      cancelled_by: null,
      created_at: '2026-11-01T12:00:00+00:00',
      allowed_actions: ['cancel', 'resend_confirmation'],
      ...overrides,
    },
  }
}

function LocationProbe() {
  const location = useLocation()
  return <p data-testid="location">{location.search}</p>
}

function renderAgenda(path = `/admin/agenda?data=${DAY}`) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <Routes>
          <Route path="/admin/login" element={<p>Tela de login</p>} />
          <Route
            path="/admin/agenda"
            element={
              <>
                <Agenda />
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </AuthProvider>
    </MemoryRouter>
  )
}

describe('Agenda', () => {
  beforeEach(() => {
    me.mockResolvedValue({ data: { id: 1, name: 'Admin Dev', email: 'admin@barberbooking.test' } })
    getBusinessSettings.mockResolvedValue({ data: { timezone: TZ } })
    listServices.mockResolvedValue({ data: services })
    listProfessionals.mockResolvedValue({ data: professionals })
    getAgenda.mockImplementation(({ date }) => Promise.resolve(agendaFor(date)))
  })

  afterEach(() => {
    vi.resetAllMocks()
  })

  it('shows the day in the barbershop time zone with statuses in words, blocks and undelivered e-mails', async () => {
    renderAgenda()

    const list = await screen.findByRole('list', { name: 'Agenda' })
    const cards = within(list).getAllByRole('button')
    expect(cards).toHaveLength(3)
    expect(cards[0]).toHaveAccessibleName(/10:00–10:45, João Silva, Corte degradê, Confirmado/)
    expect(cards[1]).toHaveTextContent('Cancelado')
    expect(cards[2]).toHaveTextContent('E-mail de confirmação não enviado')
    expect(within(list).getByText(/Fechamento · 16:00–18:00/)).toBeInTheDocument()
    expect(within(list).getByText(/Reforma/)).toBeInTheDocument()
    expect(getAgenda).toHaveBeenCalledWith({ date: DAY, professionalId: '' })
  })

  it('moves between days through the URL and filters by status without a new request', async () => {
    const user = userEvent.setup()
    renderAgenda()
    await screen.findByRole('list', { name: 'Agenda' })

    await user.click(screen.getByRole('button', { name: 'Próximo dia' }))
    await waitFor(() => expect(getAgenda).toHaveBeenLastCalledWith({ date: '2026-11-03', professionalId: '' }))
    expect(screen.getByTestId('location')).toHaveTextContent('data=2026-11-03')

    const calls = getAgenda.mock.calls.length
    await user.selectOptions(screen.getByLabelText('Status'), 'cancelled')
    const list = await screen.findByRole('list', { name: 'Agenda' })
    expect(within(list).getAllByRole('button')).toHaveLength(1)
    expect(within(list).getByRole('button')).toHaveTextContent('Pedro Alves')
    expect(getAgenda.mock.calls.length).toBe(calls)
  })

  it('opens the details and cancels only after an explicit confirmation', async () => {
    const user = userEvent.setup()
    getAppointment.mockResolvedValue(details())
    runAppointmentAction.mockResolvedValue({
      ...details({ status: 'cancelled', cancelled_by: 'admin', cancelled_at: '2026-11-02T11:00:00+00:00', allowed_actions: [] }),
      meta: { changed: true },
    })
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: /João Silva/ }))
    const dialog = await screen.findByRole('dialog', { name: 'Detalhes da reserva' })
    expect(within(dialog).getByText('joao@example.com')).toBeInTheDocument()
    expect(within(dialog).getByText(/R\$\s?55,90/)).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Cancelar reserva' }))
    expect(runAppointmentAction).not.toHaveBeenCalled()
    expect(within(dialog).getByRole('button', { name: 'Manter reserva' })).toHaveFocus()

    const before = getAgenda.mock.calls.length
    await user.click(within(dialog).getByRole('button', { name: 'Confirmar cancelamento' }))

    expect(runAppointmentAction).toHaveBeenCalledWith('01AAA', 'cancel')
    expect(await within(dialog).findByText('Reserva cancelada. O horário foi liberado.')).toBeInTheDocument()
    expect(within(dialog).getByText(/Cancelada pela barbearia/)).toBeInTheDocument()
    await waitFor(() => expect(getAgenda.mock.calls.length).toBeGreaterThan(before))
  })

  it('reports a resend as accepted by the mail server, never as delivered', async () => {
    const user = userEvent.setup()
    getAppointment.mockResolvedValue(details())
    runAppointmentAction.mockResolvedValue({ ...details(), meta: { attempt_status: 'sent' } })
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: /João Silva/ }))
    const dialog = await screen.findByRole('dialog')
    await user.click(await within(dialog).findByRole('button', { name: 'Reenviar confirmação' }))

    expect(runAppointmentAction).toHaveBeenCalledWith('01AAA', 'resend_confirmation')
    expect(await within(dialog).findByText(/a entrega não é garantida/)).toBeInTheDocument()
  })

  it('creates a scheduled booking from the admin availability and keeps the same key on a retry', async () => {
    const user = userEvent.setup()
    getAdminAvailability.mockResolvedValue({
      data: { timezone: TZ, date: DAY, slots: [{ starts_at: '2026-11-02T12:15:00+00:00', ends_at: '2026-11-02T13:00:00+00:00' }] },
    })
    createAdminAppointment
      .mockRejectedValueOnce(new ApiError(0, 'NETWORK_ERROR', 'offline'))
      .mockResolvedValueOnce({
        data: { ...details().data, starts_at: '2026-11-02T12:15:00+00:00', customer: { name: 'Cliente Balcão', email: null, phone: null }, notification_status: 'none' },
      })
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: 'Novo agendamento' }))
    const dialog = await screen.findByRole('dialog', { name: 'Novo agendamento' })
    // Inactive services are not offered.
    expect(within(dialog).queryByRole('option', { name: /Barba/ })).not.toBeInTheDocument()
    await user.selectOptions(within(dialog).getByLabelText('Serviço'), '1')
    await user.selectOptions(within(dialog).getByLabelText('Profissional'), '7')
    await user.selectOptions(await within(dialog).findByLabelText('Horário'), '2026-11-02T12:15:00+00:00')
    await user.type(within(dialog).getByLabelText('Nome do cliente'), 'Cliente Balcão')

    await user.click(within(dialog).getByRole('button', { name: 'Agendar' }))
    expect(await within(dialog).findByText(/Não foi possível confirmar/)).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Nome do cliente')).toHaveValue('Cliente Balcão')

    await user.click(within(dialog).getByRole('button', { name: 'Agendar' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    const [first, second] = createAdminAppointment.mock.calls
    expect(first[0]).toEqual({
      mode: 'scheduled',
      service_id: 1,
      professional_id: 7,
      starts_at: '2026-11-02T12:15:00+00:00',
      customer_name: 'Cliente Balcão',
      customer_email: null,
      customer_phone: null,
    })
    expect(second[1]).toBe(first[1])
    expect(getAdminAvailability).toHaveBeenCalledWith({ serviceId: '1', professionalId: '7', date: DAY })
    expect(await screen.findByText(/Cliente Balcão/)).toBeInTheDocument()
  })

  it('keeps everything typed and refreshes the times when the slot was taken meanwhile', async () => {
    const user = userEvent.setup()
    getAdminAvailability.mockResolvedValue({
      data: { timezone: TZ, date: DAY, slots: [{ starts_at: '2026-11-02T12:15:00+00:00', ends_at: '2026-11-02T13:00:00+00:00' }] },
    })
    createAdminAppointment.mockRejectedValue(new ApiError(409, 'SLOT_UNAVAILABLE', 'O profissional já tem um atendimento nesse horário.'))
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: 'Novo agendamento' }))
    const dialog = await screen.findByRole('dialog')
    await user.selectOptions(within(dialog).getByLabelText('Serviço'), '1')
    await user.selectOptions(within(dialog).getByLabelText('Profissional'), '7')
    await user.selectOptions(await within(dialog).findByLabelText('Horário'), '2026-11-02T12:15:00+00:00')
    await user.type(within(dialog).getByLabelText('Nome do cliente'), 'Ana')
    await user.type(within(dialog).getByLabelText('E-mail (opcional)'), 'ana@example.com')
    const before = getAdminAvailability.mock.calls.length

    await user.click(within(dialog).getByRole('button', { name: 'Agendar' }))

    expect(await within(dialog).findByText(/já tem um atendimento nesse horário/)).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Nome do cliente')).toHaveValue('Ana')
    expect(within(dialog).getByLabelText('E-mail (opcional)')).toHaveValue('ana@example.com')
    await waitFor(() => expect(getAdminAvailability.mock.calls.length).toBeGreaterThan(before))
  })

  it('"Atender agora" never sends a start time: the server decides it', async () => {
    const user = userEvent.setup()
    createAdminAppointment.mockResolvedValue({
      data: { ...details().data, source: 'admin', customer: { name: 'Walk-in', email: null, phone: null }, notification_status: 'none' },
    })
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: 'Atender agora' }))
    const dialog = await screen.findByRole('dialog', { name: 'Atender agora' })
    expect(within(dialog).queryByLabelText('Horário')).not.toBeInTheDocument()
    await user.selectOptions(within(dialog).getByLabelText('Serviço'), '1')
    await user.selectOptions(within(dialog).getByLabelText('Profissional'), '7')
    await user.type(within(dialog).getByLabelText('Nome do cliente'), 'Walk-in')
    await user.click(within(dialog).getByRole('button', { name: 'Iniciar atendimento' }))

    await waitFor(() => expect(createAdminAppointment).toHaveBeenCalledTimes(1))
    const payload = createAdminAppointment.mock.calls[0][0]
    expect(payload.mode).toBe('now')
    expect(payload).not.toHaveProperty('starts_at')
  })

  it('offers the way back to the login when the session expires', async () => {
    getAgenda.mockRejectedValue(new ApiError(401, 'UNAUTHENTICATED', 'Autenticação necessária.'))
    renderAgenda()

    expect(await screen.findByText('Sua sessão expirou. Entre novamente para continuar.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Entrar novamente' })).toHaveAttribute('href', '/admin/login')
  })

  it('switching the language keeps the day, the filters and the open form', async () => {
    const user = userEvent.setup()
    renderAgenda(`/admin/agenda?data=${DAY}&status=confirmed`)
    await screen.findByRole('list', { name: 'Agenda' })
    await user.click(screen.getByRole('button', { name: 'Atender agora' }))
    await user.type(await screen.findByLabelText('Nome do cliente'), 'Walk-in')
    const calls = getAgenda.mock.calls.length

    await act(() => i18n.changeLanguage('en'))

    expect(await screen.findByLabelText('Customer name')).toHaveValue('Walk-in')
    expect(screen.getByTestId('location')).toHaveTextContent('status=confirmed')
    expect(getAgenda.mock.calls.length).toBe(calls)
  })

  it('signs out from the shell and returns to the login', async () => {
    const user = userEvent.setup()
    logout.mockResolvedValue(null)
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: 'Sair' }))

    await waitFor(() => expect(screen.getByText('Tela de login')).toBeInTheDocument())
    expect(logout).toHaveBeenCalledTimes(1)
  })

  it('keeps Escape and focus working after "keep booking" removes the focused button', async () => {
    const user = userEvent.setup()
    getAppointment.mockResolvedValue(details())
    renderAgenda()

    const card = await screen.findByRole('button', { name: /João Silva/ })
    await user.click(card)
    const dialog = await screen.findByRole('dialog', { name: 'Detalhes da reserva' })
    await user.click(await within(dialog).findByRole('button', { name: 'Cancelar reserva' }))
    await user.click(within(dialog).getByRole('button', { name: 'Manter reserva' }))

    expect(within(dialog).getByRole('button', { name: 'Cancelar reserva' })).toHaveFocus()
    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(card).toHaveFocus()
  })

  it('Escape still closes the dialog when focus fell back to the page', async () => {
    const user = userEvent.setup()
    getAppointment.mockResolvedValue(details())
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: /João Silva/ }))
    await screen.findByRole('dialog')
    act(() => document.activeElement.blur())
    expect(document.body).toHaveFocus()

    await user.tab()
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true)
    await user.keyboard('{Escape}')
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('offers no resend for a booking without e-mail', async () => {
    const user = userEvent.setup()
    getAppointment.mockResolvedValue(
      details({ source: 'admin', customer: { name: 'João Silva', email: null, phone: null }, notification: null, notification_status: 'none', allowed_actions: ['cancel'] })
    )
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: /João Silva/ }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByRole('button', { name: 'Cancelar reserva' })).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Reenviar confirmação' })).not.toBeInTheDocument()
    expect(within(dialog).getByText('Pela barbearia')).toBeInTheDocument()
  })

  it('moves focus to the first invalid field when the new booking is incomplete', async () => {
    const user = userEvent.setup()
    renderAgenda()

    await user.click(await screen.findByRole('button', { name: 'Novo agendamento' }))
    const dialog = await screen.findByRole('dialog', { name: 'Novo agendamento' })
    await user.click(within(dialog).getByRole('button', { name: 'Agendar' }))

    const name = within(dialog).getByLabelText('Nome do cliente')
    expect(name).toHaveAttribute('aria-invalid', 'true')
    const invalid = dialog.querySelectorAll('[aria-invalid="true"]:not(:disabled)')
    expect(invalid.length).toBeGreaterThan(0)
    await waitFor(() => expect(invalid[0]).toHaveFocus())
    expect(createAdminAppointment).not.toHaveBeenCalled()
  })

  describe('layout by screen width', () => {
    function viewport(wide) {
      window.matchMedia = vi.fn((query) => ({
        matches: wide && query === '(min-width: 960px)',
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
      }))
    }

    afterEach(() => {
      delete window.matchMedia
    })

    it('phones get a chronological list and "new booking" in the page flow, never over the cards', async () => {
      viewport(false)
      renderAgenda()

      const list = await screen.findByRole('list', { name: 'Agenda' })
      expect(screen.queryByRole('list', { name: 'Agenda de Rafael Almeida' })).not.toBeInTheDocument()
      const add = screen.getAllByRole('button', { name: 'Novo agendamento' })
      expect(add).toHaveLength(1)
      // Regression: a fixed button covered the first card at 320 px. It now
      // sits in the page header, before the list, like on desktop.
      expect(add[0].closest('header.agenda-header')).not.toBeNull()
      expect(add[0].compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
      expect(document.querySelector('.agenda-fab')).toBeNull()
      expect(screen.getByRole('button', { name: 'Atender agora' })).toBeInTheDocument()
    })

    it('phones keep the date in view and fold the other filters, saying how many are active', async () => {
      viewport(false)
      const user = userEvent.setup()
      renderAgenda()
      await screen.findByRole('list', { name: 'Agenda' })

      expect(document.getElementById('agenda-date-input').closest('details')).toBeNull()
      const more = screen.getByText('Mais filtros').closest('details')
      expect(more).not.toHaveAttribute('open')
      expect(within(more).getByLabelText('Status')).toBeInTheDocument()

      await user.click(screen.getByText('Mais filtros'))
      expect(more).toHaveAttribute('open')
      await user.selectOptions(within(more).getByLabelText('Status'), 'cancelled')
      expect(within(more).getByText('Mais filtros · 1 ativo')).toBeInTheDocument()
    })

    it('phones open the folded filters when the URL already filters', async () => {
      viewport(false)
      renderAgenda(`/admin/agenda?data=${DAY}&status=cancelled&profissional=7`)
      await screen.findByRole('list', { name: 'Agenda' })

      const summary = screen.getByText('Mais filtros · 2 ativos')
      expect(summary.closest('details')).toHaveAttribute('open')
    })

    it('desktop gets one column per professional and the action in the header', async () => {
      viewport(true)
      renderAgenda()

      const column = await screen.findByRole('list', { name: 'Agenda de Rafael Almeida' })
      expect(within(column).getByRole('button', { name: /João Silva/ })).toHaveAttribute('title', expect.stringMatching(/João Silva/))
      expect(screen.queryByRole('list', { name: 'Agenda' })).not.toBeInTheDocument()
      const add = screen.getAllByRole('button', { name: 'Novo agendamento' })
      expect(add).toHaveLength(1)
      expect(add[0].closest('header.agenda-header')).not.toBeNull()
      expect(screen.queryByText('Mais filtros')).not.toBeInTheDocument()
      expect(screen.getByLabelText('Profissional')).toBeVisible()
    })
  })
})
