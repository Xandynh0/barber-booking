import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'
import { AdminNav } from './AdminNav'
import { LanguageSwitcher } from './LanguageSwitcher'
import '../pages/admin/admin.css'

export function AdminLayout({ children }) {
  const { t } = useTranslation()
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
          <h1 className="admin-brand admin-brand--inline">{t('common.brand')}</h1>
          <p className="admin-subtitle">{t('adminLayout.signedInAs', { name: user?.name, email: user?.email })}</p>
        </div>
        <div className="admin-shell-header-actions">
          <LanguageSwitcher />
          <button
            type="button"
            className="admin-button admin-button--secondary"
            onClick={handleLogout}
            disabled={signingOut}
          >
            {signingOut ? t('common.actions.signingOut') : t('common.actions.signOut')}
          </button>
        </div>
      </header>

      <AdminNav />

      {children}
    </main>
  )
}
