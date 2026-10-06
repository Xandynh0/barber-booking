import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import './ProtectedRoute.css'

export function ProtectedRoute({ children }) {
  const { status } = useAuth()

  if (status === 'checking') {
    return (
      <main className="admin-checking">
        <p>Verificando sessão...</p>
      </main>
    )
  }

  if (status !== 'authenticated') {
    return <Navigate to="/admin/login" replace />
  }

  return children
}
