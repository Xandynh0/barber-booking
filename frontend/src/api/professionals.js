import { apiFetch } from './client'

export function listProfessionals() {
  return apiFetch('/api/v1/admin/professionals')
}

export function createProfessional(payload) {
  return apiFetch('/api/v1/admin/professionals', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateProfessional(id, payload) {
  return apiFetch(`/api/v1/admin/professionals/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}
