import { apiFetch, ensureCsrfCookie } from './client'

export async function login({ email, password }) {
  await ensureCsrfCookie()

  return apiFetch('/api/v1/admin/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export async function me() {
  return apiFetch('/api/v1/admin/me')
}

export async function logout() {
  return apiFetch('/api/v1/admin/logout', { method: 'POST' })
}
