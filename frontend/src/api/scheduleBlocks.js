import { apiFetch } from './client'

export function listScheduleBlocks() {
  return apiFetch('/api/v1/admin/schedule-blocks')
}

export function createScheduleBlock(payload) {
  return apiFetch('/api/v1/admin/schedule-blocks', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function deleteScheduleBlock(id) {
  return apiFetch(`/api/v1/admin/schedule-blocks/${id}`, {
    method: 'DELETE',
  })
}
