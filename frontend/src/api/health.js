export async function fetchHealth() {
  const response = await fetch('/api/health')
  const body = await response.json().catch(() => null)

  if (!response.ok) {
    throw new Error(body?.error?.message ?? 'Falha ao consultar a API')
  }

  return body
}
