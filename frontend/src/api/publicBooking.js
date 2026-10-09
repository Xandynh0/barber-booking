import { apiFetch, ensureCsrfCookie } from './client'

// Public, unauthenticated endpoints used by the homepage and the booking
// journey (docs/planejamento-barbearia-mvp.md, seções 11, 16 e 17).

export function getBusiness() {
  return apiFetch('/api/v1/public/business')
}

export function listPublicServices() {
  return apiFetch('/api/v1/public/services')
}

export function listServiceProfessionals(serviceId) {
  return apiFetch(`/api/v1/public/services/${serviceId}/professionals`)
}

export function getAvailability({ serviceId, professionalId, date }) {
  const query = new URLSearchParams({
    service_id: String(serviceId),
    professional_id: String(professionalId),
    date,
  })

  return apiFetch(`/api/v1/public/availability?${query}`)
}

/**
 * The browser request is same-origin, so Sanctum treats it as stateful and
 * verifies CSRF on this POST: the XSRF cookie is (re)fetched first.
 * `idempotencyKey` identifies one logical booking attempt — callers reuse it
 * when retrying the very same payload.
 */
export async function createAppointment(payload, idempotencyKey) {
  await ensureCsrfCookie()

  return apiFetch('/api/v1/public/appointments', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(payload),
  })
}
