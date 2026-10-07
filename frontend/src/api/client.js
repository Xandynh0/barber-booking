import i18n from '../i18n'

export class ApiError extends Error {
  constructor(status, code, message, fields) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields ?? null
  }
}

function readCookie(name) {
  const pattern = new RegExp(`(?:^|; )${name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1')}=([^;]*)`)
  const match = document.cookie.match(pattern)

  return match ? decodeURIComponent(match[1]) : null
}

/**
 * Must be called before any state-changing admin request — Sanctum's SPA
 * auth validates the CSRF cookie this sets against an X-XSRF-TOKEN header.
 */
export async function ensureCsrfCookie() {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Accept-Language': i18n.language },
  })
}

export async function apiFetch(path, options = {}) {
  const method = (options.method ?? 'GET').toUpperCase()
  const headers = { Accept: 'application/json', 'Accept-Language': i18n.language, ...options.headers }

  if (method !== 'GET' && method !== 'HEAD') {
    const token = readCookie('XSRF-TOKEN')
    if (token) {
      headers['X-XSRF-TOKEN'] = token
    }
    if (options.body) {
      headers['Content-Type'] = 'application/json'
    }
  }

  let response
  try {
    response = await fetch(path, { ...options, method, headers, credentials: 'same-origin' })
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', i18n.t('common.errors.network'))
  }

  if (response.status === 204) {
    return null
  }

  const body = await response.json().catch(() => null)

  if (!response.ok) {
    const code = body?.error?.code ?? (response.status === 419 ? 'SESSION_EXPIRED' : 'UNKNOWN_ERROR')
    // body?.error?.message is this app's own contract (bootstrap/app.php,
    // AuthController, etc.) and is already localized server-side — safe to
    // show as-is. A response shape WITHOUT that "error" key (an uncaught
    // exception Laravel rendered its own way) never reaches this message;
    // it falls straight to the generic, localized, non-leaking fallback.
    const message = body?.error?.message ?? i18n.t('common.errors.generic')

    throw new ApiError(response.status, code, message, body?.error?.fields)
  }

  return body
}
