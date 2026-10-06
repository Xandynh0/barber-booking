import { apiFetch } from './client'

export function getWorkingHours(professionalId) {
  return apiFetch(`/api/v1/admin/professionals/${professionalId}/working-hours`)
}

export function updateWorkingHours(professionalId, payload) {
  return apiFetch(`/api/v1/admin/professionals/${professionalId}/working-hours`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}
