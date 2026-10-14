import { apiFetch, ensureCsrfCookie } from './client'

// Admin agenda endpoints (docs/desenvolvimento.md, "Agenda administrativa").
// Reservations are addressed by `public_id`.

export function getAgenda({ date, professionalId }) {
  const query = new URLSearchParams({ date })
  if (professionalId) query.set('professional_id', String(professionalId))

  return apiFetch(`/api/v1/admin/agenda?${query}`)
}

export function getAppointment(publicId) {
  return apiFetch(`/api/v1/admin/appointments/${encodeURIComponent(publicId)}`)
}

export function getAdminAvailability({ serviceId, professionalId, date }) {
  const query = new URLSearchParams({
    service_id: String(serviceId),
    professional_id: String(professionalId),
    date,
  })

  return apiFetch(`/api/v1/admin/availability?${query}`)
}

/**
 * `idempotencyKey` identifies one logical attempt: reused when retrying the
 * very same payload, replaced when anything in it changes.
 */
export async function createAdminAppointment(payload, idempotencyKey) {
  await ensureCsrfCookie()

  return apiFetch('/api/v1/admin/appointments', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(payload),
  })
}

const ACTION_PATHS = {
  cancel: 'cancel',
  complete: 'complete',
  no_show: 'no-show',
  resend_confirmation: 'resend-confirmation',
}

/** One of the `allowed_actions` the API returned for this reservation. */
export async function runAppointmentAction(publicId, action) {
  await ensureCsrfCookie()

  return apiFetch(`/api/v1/admin/appointments/${encodeURIComponent(publicId)}/${ACTION_PATHS[action]}`, {
    method: 'POST',
  })
}
