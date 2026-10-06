import { apiFetch } from './client'

export function getBusinessSettings() {
  return apiFetch('/api/v1/admin/business-settings')
}
