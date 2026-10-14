import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'

export function ProtectedRoute({ children }) {
  const { t } = useTranslation()
  const { status } = useAuth()

  if (status === 'checking') {
    return (
      <main className="admin-checking">
        <p role="status" className="loading-state">
          {t('common.checkingSession')}
        </p>
      </main>
    )
  }

  if (status !== 'authenticated') {
    return <Navigate to="/admin/login" replace />
  }

  return children
}
