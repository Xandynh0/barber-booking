import { useId, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../api/client'
import { LanguageSwitcher } from '../../components/LanguageSwitcher'
import { BarberPoleIcon, ScissorsIcon } from '../../components/PublicIcons'
import { useAuth } from '../../context/AuthContext'
import './login.css'

function errorMessageFor(t, error) {
  if (error.code === 'INVALID_CREDENTIALS') {
    return t('auth.errors.invalidCredentials')
  }
  if (error.code === 'RATE_LIMITED') {
    return t('auth.errors.rateLimited')
  }
  if (error.code === 'NETWORK_ERROR') {
    return t('auth.errors.network')
  }
  if (error.code === 'SESSION_EXPIRED') {
    return t('auth.errors.sessionExpired')
  }

  return error.message || t('auth.errors.generic')
}

function Login() {
  const { t } = useTranslation()
  const { status, sessionExpired, login } = useAuth()
  const navigate = useNavigate()
  const emailId = useId()
  const passwordId = useId()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(null)

  // sessionExpired can flip to true after the initial render (the /me check
  // resolves asynchronously), so the message is derived on every render
  // instead of captured once in useState's initial value.
  const effectiveFormError = formError ?? (sessionExpired ? t('auth.errors.sessionExpired') : null)

  if (status === 'checking') {
    return (
      <main className="admin-checking">
        <p role="status" className="loading-state">
          {t('common.checkingSession')}
        </p>
      </main>
    )
  }

  if (status === 'authenticated') {
    return <Navigate to="/admin/agenda" replace />
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (submitting) return

    setSubmitting(true)
    setFieldErrors({})
    setFormError(null)

    try {
      await login({ email, password })
      navigate('/admin/agenda', { replace: true })
    } catch (error) {
      if (error instanceof ApiError && error.code === 'VALIDATION_ERROR') {
        setFieldErrors(error.fields ?? {})
        setFormError(null)
      } else if (error instanceof ApiError) {
        setFormError(errorMessageFor(t, error))
      } else {
        setFormError(t('auth.errors.generic'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-page">
      <aside className="login-board on-ink" aria-hidden="true">
        <div className="pole-stripe" />
        <div className="login-board-inner">
          <ScissorsIcon size={34} className="login-board-mark" />
          <p className="login-board-brand">{t('common.brand')}</p>
          <p className="login-board-tagline">{t('home.tagline')}</p>
          <BarberPoleIcon size={72} className="login-board-pole" />
        </div>
      </aside>

      <main className="login-main">
        <div className="login-topline">
          <Link className="quiet-button" to="/">
            {t('auth.backToSite')}
          </Link>
          <LanguageSwitcher />
        </div>

        <form className="login-form" onSubmit={handleSubmit} noValidate>
          <p className="eyebrow">{t('auth.subtitle')}</p>
          <h1 className="login-title">{t('common.brand')}</h1>
          <p className="login-lead">{t('auth.welcome')}</p>

          {effectiveFormError && (
            <p className="notice notice--error" role="alert">
              {effectiveFormError}
            </p>
          )}

          <div className="field">
            <label htmlFor={emailId}>{t('auth.emailLabel')}</label>
            <input
              id={emailId}
              name="email"
              type="email"
              autoComplete="username"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              aria-invalid={Boolean(fieldErrors.email)}
              aria-describedby={fieldErrors.email ? `${emailId}-error` : undefined}
              required
            />
            {fieldErrors.email && (
              <span id={`${emailId}-error`} className="field-error">
                {fieldErrors.email[0]}
              </span>
            )}
          </div>

          <div className="field">
            <label htmlFor={passwordId}>{t('auth.passwordLabel')}</label>
            <input
              id={passwordId}
              name="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              aria-invalid={Boolean(fieldErrors.password)}
              aria-describedby={fieldErrors.password ? `${passwordId}-error` : undefined}
              required
            />
            {fieldErrors.password && (
              <span id={`${passwordId}-error`} className="field-error">
                {fieldErrors.password[0]}
              </span>
            )}
          </div>

          <button type="submit" className="primary-button login-submit" disabled={submitting}>
            {submitting ? t('auth.submitting') : t('auth.submit')}
          </button>
        </form>
      </main>
    </div>
  )
}

export default Login
