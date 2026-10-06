import { useId, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import './admin.css'

function errorMessageFor(error) {
  if (error.code === 'INVALID_CREDENTIALS') {
    return 'E-mail ou senha inválidos.'
  }
  if (error.code === 'RATE_LIMITED') {
    return 'Muitas tentativas. Aguarde um momento e tente novamente.'
  }
  if (error.code === 'NETWORK_ERROR') {
    return 'Não foi possível conectar ao servidor. Verifique sua conexão.'
  }
  if (error.code === 'SESSION_EXPIRED') {
    return 'Sessão expirada. Entre novamente.'
  }

  return error.message || 'Não foi possível entrar. Tente novamente.'
}

function Login() {
  const { status, sessionExpired, login } = useAuth()
  const navigate = useNavigate()
  const emailId = useId()
  const passwordId = useId()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState(
    sessionExpired ? 'Sessão expirada. Entre novamente.' : null
  )

  if (status === 'checking') {
    return (
      <main className="admin-checking">
        <p>Verificando sessão...</p>
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
        setFormError(errorMessageFor(error))
      } else {
        setFormError('Não foi possível entrar. Tente novamente.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <main className="admin-page admin-page--centered">
      <form className="admin-card admin-login-card" onSubmit={handleSubmit} noValidate>
        <h1 className="admin-brand">Barber Booking</h1>
        <p className="admin-subtitle">Acesso administrativo</p>

        {formError && (
          <p className="admin-form-error" role="alert">
            {formError}
          </p>
        )}

        <div className="admin-field">
          <label htmlFor={emailId}>E-mail</label>
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
            <span id={`${emailId}-error`} className="admin-field-error">
              {fieldErrors.email[0]}
            </span>
          )}
        </div>

        <div className="admin-field">
          <label htmlFor={passwordId}>Senha</label>
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
            <span id={`${passwordId}-error`} className="admin-field-error">
              {fieldErrors.password[0]}
            </span>
          )}
        </div>

        <button type="submit" className="admin-button" disabled={submitting}>
          {submitting ? 'Entrando...' : 'Entrar'}
        </button>
      </form>
    </main>
  )
}

export default Login
