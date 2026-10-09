import i18n from '../i18n'

export async function fetchHealth() {
  const response = await fetch('/api/health', {
    headers: { 'Accept-Language': i18n.language },
  })
  const body = await response.json().catch(() => null)

  if (!response.ok) {
    throw new Error(body?.error?.message ?? i18n.t('home.statusDetailOffline'))
  }

  return body
}
