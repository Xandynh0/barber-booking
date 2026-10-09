import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { apiFetch } from './client'

describe('apiFetch', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn())
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('returns parsed JSON on a successful response', async () => {
    fetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: { id: 1 } }),
    })

    await expect(apiFetch('/api/v1/admin/me')).resolves.toEqual({ data: { id: 1 } })
  })

  it('returns null for a 204 response without parsing a body', async () => {
    fetch.mockResolvedValue({
      ok: true,
      status: 204,
      json: async () => {
        throw new Error('204 responses have no body')
      },
    })

    await expect(apiFetch('/api/v1/admin/logout', { method: 'POST' })).resolves.toBeNull()
  })

  it('throws an ApiError carrying the server code, message and fields', async () => {
    fetch.mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({
        error: {
          code: 'VALIDATION_ERROR',
          message: 'Dados inválidos.',
          fields: { email: ['The email field is required.'] },
        },
      }),
    })

    await expect(apiFetch('/api/v1/admin/login', { method: 'POST', body: '{}' })).rejects.toMatchObject({
      status: 422,
      code: 'VALIDATION_ERROR',
      fields: { email: ['The email field is required.'] },
    })
  })

  it('maps a fetch-level failure to a NETWORK_ERROR ApiError instead of throwing raw', async () => {
    fetch.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(apiFetch('/api/v1/admin/me')).rejects.toMatchObject({ code: 'NETWORK_ERROR' })
  })

  it('uses a translated message (not a raw i18n key) for a fetch-level failure', async () => {
    fetch.mockRejectedValue(new TypeError('Failed to fetch'))

    await expect(apiFetch('/api/v1/admin/me')).rejects.toMatchObject({
      code: 'NETWORK_ERROR',
      message: 'Não foi possível conectar ao servidor. Verifique sua conexão.',
    })
  })

  it('uses a translated generic message when an error response has no error body', async () => {
    fetch.mockResolvedValue({ ok: false, status: 500, json: async () => ({ message: 'Server Error' }) })

    await expect(apiFetch('/api/v1/admin/me')).rejects.toMatchObject({
      code: 'UNKNOWN_ERROR',
      message: 'Ocorreu um erro inesperado. Tente novamente.',
    })
  })

  it('sends the decoded XSRF-TOKEN cookie as the X-XSRF-TOKEN header on non-GET requests', async () => {
    document.cookie = 'XSRF-TOKEN=abc%20123'
    fetch.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) })

    await apiFetch('/api/v1/admin/login', { method: 'POST', body: '{}' })

    const [, options] = fetch.mock.calls.at(-1)
    expect(options.headers['X-XSRF-TOKEN']).toBe('abc 123')
  })

  it('does not attach a CSRF header on GET requests', async () => {
    document.cookie = 'XSRF-TOKEN=abc%20123'
    fetch.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) })

    await apiFetch('/api/v1/admin/me')

    const [, options] = fetch.mock.calls.at(-1)
    expect(options.headers['X-XSRF-TOKEN']).toBeUndefined()
  })
})
