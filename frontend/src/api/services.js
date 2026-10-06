import { apiFetch } from './client'

export function listServices() {
  return apiFetch('/api/v1/admin/services')
}

export function createService(payload) {
  return apiFetch('/api/v1/admin/services', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateService(id, payload) {
  return apiFetch(`/api/v1/admin/services/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}
