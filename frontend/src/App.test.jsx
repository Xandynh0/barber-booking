import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'

describe('App', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('shows the brand and starts in checking state', () => {
    fetch.mockReturnValue(new Promise(() => {}))

    render(<App />)

    expect(screen.getByText('Barber Booking')).toBeInTheDocument()
    expect(screen.getByText('Verificando...')).toBeInTheDocument()
  })

  it('shows online status when the health endpoint responds ok', async () => {
    fetch.mockResolvedValue({
      ok: true,
      json: async () => ({ status: 'ok' }),
    })

    render(<App />)

    await waitFor(() => expect(screen.getByText('Conectado')).toBeInTheDocument())
  })

  it('shows offline status when the health endpoint fails', async () => {
    fetch.mockResolvedValue({
      ok: false,
      json: async () => ({ error: { message: 'Serviço indisponível' } }),
    })

    render(<App />)

    await waitFor(() => expect(screen.getByText('Indisponível')).toBeInTheDocument())
    expect(screen.getByText('Serviço indisponível')).toBeInTheDocument()
  })
})
