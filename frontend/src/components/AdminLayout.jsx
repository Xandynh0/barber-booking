import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { AdminNav } from './AdminNav'
import '../pages/admin/admin.css'

export function AdminLayout({ children }) {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [signingOut, setSigningOut] = useState(false)

  async function handleLogout() {
    setSigningOut(true)
    try {
      await logout()
    } finally {
      navigate('/admin/login', { replace: true })
    }
  }

  return (
    <main className="admin-page">
      <header className="admin-shell-header">
        <div>
          <h1 className="admin-brand admin-brand--inline">Barber Booking</h1>
          <p className="admin-subtitle">
            Autenticado como {user?.name} ({user?.email})
          </p>
        </div>
        <button
          type="button"
          className="admin-button admin-button--secondary"
          onClick={handleLogout}
          disabled={signingOut}
        >
          {signingOut ? 'Saindo...' : 'Sair'}
        </button>
      </header>

      <AdminNav />

      {children}
    </main>
  )
}
